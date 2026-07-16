<?php

namespace App\Tests\Service\Portal;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\NotificationType;
use App\Enum\PortalEventType;
use App\Event\PortalEventDetectedEvent;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use App\Service\Portal\CaseMonitoringService;
use App\Service\Portal\PortalJustClient;
use App\Service\Portal\PortalJustException;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CaseMonitoringServiceTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private CaseMonitoringService $service;
    private User $user;
    private Court $court;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testPrefix = 'portal-mon-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->court = new Court();
        $this->court->setName('Monitor Court ' . $this->testPrefix);
        $this->court->setCounty($this->createCounty($this->em, 'CJ'));
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setPortalCode('MonitorCourt' . uniqid());
        $this->em->persist($this->court);

        $this->em->flush();
    }

    private function createServiceWithMockClient(array $soapResponse): CaseMonitoringService
    {
        $mockClient = $this->createStub(PortalJustClient::class);
        $mockClient->method('searchByCaseNumber')->willReturn($soapResponse);

        // Get other dependencies from DI container
        $realService = static::getContainer()->get(CaseMonitoringService::class);
        $reflection = new \ReflectionClass($realService);

        // Replace portalClient with mock
        $prop = $reflection->getProperty('portalClient');
        $prop->setValue($realService, $mockClient);

        return $realService;
    }

    private function createSubmittedCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCourt($this->court);
        $case->setStatus(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('200/211/2026');
        $case->setPortalMonitoringActive(true);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testMonitorCaseDetectsNewEventsAndCreatesNotifications(): void
    {
        $case = $this->createSubmittedCase();

        $service = $this->createServiceWithMockClient([
            [
                'numar' => '200/211/2026',
                'institutie' => 'Test',
                'departament' => null,
                'categorieCaz' => null,
                'stadiuProcesual' => null,
                'obiect' => null,
                'dataModificare' => null,
                'parti' => [],
                'sedinte' => [
                    ['data' => '20.03.2026', 'complet' => 'C3', 'ora' => '09:00', 'solutie' => null, 'solutieSumar' => null, 'dataPronuntare' => null],
                ],
                'caiAtac' => [],
            ],
        ]);

        $newCount = $service->monitorCase($case);

        $this->assertSame(1, $newCount);

        // Verify event was persisted
        $events = $this->em->getRepository(CourtPortalEvent::class)->findBy(['legalCase' => $case]);
        $this->assertCount(1, $events);
        $this->assertSame(PortalEventType::HEARING_SCHEDULED, $events[0]->getEventType());
        $this->assertTrue($events[0]->isNotified());

        // Exactly one in-app portal_event row, now persisted by the dispatcher with
        // the translated notification.portal_event.* copy. The old hardcoded-Romanian
        // "Actualizare dosar %s: %s" row built directly by CaseMonitoringService is gone.
        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'portal_event',
        ]);
        $this->assertCount(1, $notifications);
        $title = $notifications[0]->getTitle();
        $this->assertStringContainsString('200/211/2026', $title);
        $this->assertStringNotContainsString('Actualizare dosar', $title);
        $translator = static::getContainer()->get('translator');
        $this->assertSame(
            $translator->trans('notification.portal_event.title', ['%case%' => '200/211/2026']),
            $title,
        );

        // Verify lastPortalCheckAt was updated
        $this->em->refresh($case);
        $this->assertNotNull($case->getLastPortalCheckAt());
    }

    public function testMonitorCaseRethrowsOnPortalFailure(): void
    {
        // monitorCase propagates PortalJustException (for async retry); synchronous
        // callers catch it in a try/catch.
        $case = $this->createSubmittedCase();

        $mockClient = $this->createStub(PortalJustClient::class);
        $mockClient->method('searchByCaseNumber')->willThrowException(new PortalJustException('SOAP down'));

        $service = static::getContainer()->get(CaseMonitoringService::class);
        (new \ReflectionClass($service))->getProperty('portalClient')->setValue($service, $mockClient);

        $this->expectException(PortalJustException::class);
        $service->monitorCase($case);
    }

    public function testMonitorCaseDispatchesPortalEventWithoutDoubleNotification(): void
    {
        $case = $this->createSubmittedCase();

        $captured = [];
        static::getContainer()->get('event_dispatcher')->addListener(
            PortalEventDetectedEvent::class,
            static function (PortalEventDetectedEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        $service = $this->createServiceWithMockClient([
            [
                'numar' => '200/211/2026',
                'institutie' => 'Test',
                'departament' => null,
                'categorieCaz' => null,
                'stadiuProcesual' => null,
                'obiect' => null,
                'dataModificare' => null,
                'parti' => [],
                'sedinte' => [
                    ['data' => '28.03.2026', 'complet' => 'C2', 'ora' => '11:00', 'solutie' => null, 'solutieSumar' => null, 'dataPronuntare' => null],
                ],
                'caiAtac' => [],
            ],
        ]);

        $service->monitorCase($case);

        self::assertCount(1, $captured);
        self::assertSame($case->getId(), $captured[0]->case->getId());

        // After consolidation the dispatcher (via the subscriber) persists the single
        // in-app portal_event row; CaseMonitoringService no longer creates one, so
        // there is exactly one row per event, not a duplicate.
        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'portal_event',
        ]);
        $this->assertCount(1, $notifications);
    }

    public function testMonitorCaseReturnsZeroWhenNoNewEvents(): void
    {
        $case = $this->createSubmittedCase();

        $service = $this->createServiceWithMockClient([]);

        $newCount = $service->monitorCase($case);

        $this->assertSame(0, $newCount);
        $this->em->refresh($case);
        $this->assertNotNull($case->getLastPortalCheckAt());
    }

    public function testMonitorCaseSkipsCaseWithoutCourt(): void
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('300/2026');
        $this->em->persist($case);
        $this->em->flush();

        $service = static::getContainer()->get(CaseMonitoringService::class);
        $newCount = $service->monitorCase($case);

        $this->assertSame(0, $newCount);
    }

    public function testMonitorCasePassesCourtCaseNumberToPortal(): void
    {
        $case = $this->createSubmittedCase();

        $mockClient = $this->createMock(PortalJustClient::class);
        $mockClient->expects($this->once())
            ->method('searchByCaseNumber')
            ->with('200/211/2026', $this->court->getPortalCode())
            ->willReturn([]);

        $service = static::getContainer()->get(CaseMonitoringService::class);
        (new \ReflectionClass($service))->getProperty('portalClient')->setValue($service, $mockClient);

        $service->monitorCase($case);
    }

    public function testMonitorCaseSkipsCaseWithoutCourtCaseNumber(): void
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCourt($this->court);
        $case->setStatus(CaseStatus::DOSAR_INREGISTRAT);
        $case->setPortalMonitoringActive(true);
        $this->em->persist($case);
        $this->em->flush();

        $service = static::getContainer()->get(CaseMonitoringService::class);

        $this->assertSame(0, $service->monitorCase($case));
    }

    public function testMonitorCaseSkipsCaseWithoutPortalCode(): void
    {
        $courtNoCode = new Court();
        $courtNoCode->setName('No Portal ' . $this->testPrefix);
        $courtNoCode->setCounty($this->createCounty($this->em, 'CJ'));
        $courtNoCode->setType(CourtType::JUDECATORIE);
        $this->em->persist($courtNoCode);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCourt($courtNoCode);
        $case->setStatus(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('400/2026');
        $case->setPortalMonitoringActive(true);
        $this->em->persist($case);
        $this->em->flush();

        $service = static::getContainer()->get(CaseMonitoringService::class);
        $newCount = $service->monitorCase($case);

        $this->assertSame(0, $newCount);
    }

    public function testMonitorCaseCreatesAuditLog(): void
    {
        $case = $this->createSubmittedCase();

        $service = $this->createServiceWithMockClient([
            [
                'numar' => '200/211/2026',
                'institutie' => 'Test',
                'departament' => null,
                'categorieCaz' => null,
                'stadiuProcesual' => null,
                'obiect' => null,
                'dataModificare' => null,
                'parti' => [],
                'sedinte' => [
                    ['data' => '25.03.2026', 'complet' => 'C1', 'ora' => '10:00', 'solutie' => null, 'solutieSumar' => null, 'dataPronuntare' => null],
                ],
                'caiAtac' => [],
            ],
        ]);

        $service->monitorCase($case);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => 'CourtPortalEvent',
            'entityId' => (string) $case->getId(),
            'action' => 'portal_event_detected',
        ]);
        $this->assertNotEmpty($logs);
        $this->assertSame('hearing_scheduled', $logs[0]->getNewData()['eventType']);
    }

    private function createServiceWithThrowingClient(): CaseMonitoringService
    {
        $mockClient = $this->createStub(PortalJustClient::class);
        $mockClient->method('searchByCaseNumber')->willThrowException(new PortalJustException('SOAP down'));

        $service = static::getContainer()->get(CaseMonitoringService::class);
        (new \ReflectionClass($service))->getProperty('portalClient')->setValue($service, $mockClient);

        return $service;
    }

    /**
     * Replace the dispatcher with an object spy that records every dispatch by
     * reference (no closure, no email/in-app side effects). Returns the spy so
     * callers can assert on its public $dispatches array.
     */
    private function injectDispatcherSpy(CaseMonitoringService $service): object
    {
        $spy = new class implements NotificationDispatcherInterface {
            /** @var NotificationDispatch[] */
            public array $dispatches = [];

            public function dispatch(NotificationDispatch $request): void
            {
                $this->dispatches[] = $request;
            }
        };
        (new \ReflectionClass($service))->getProperty('notificationDispatcher')->setValue($service, $spy);

        return $spy;
    }

    private function setPortalFailures(LegalCase $case, int $count): void
    {
        (new \ReflectionProperty(LegalCase::class, 'portalConsecutiveFailures'))->setValue($case, $count);
        $this->em->flush();
    }

    public function testMonitorCaseIncrementsFailuresAndRethrowsBelowThreshold(): void
    {
        $case = $this->createSubmittedCase();
        $service = $this->createServiceWithThrowingClient();

        try {
            $service->monitorCase($case);
            $this->fail('Expected PortalJustException below the failure threshold');
        } catch (PortalJustException) {
            // expected: streak below MAX propagates for async retry
        }

        $this->em->refresh($case);
        $this->assertSame(1, $case->getPortalConsecutiveFailures());
        $this->assertTrue($case->isPortalMonitoringActive());
    }

    public function testMonitorCaseResetsFailuresOnSuccessfulQuery(): void
    {
        $case = $this->createSubmittedCase();
        $this->setPortalFailures($case, 3);

        // Empty response is a successful query: it must clear the streak.
        $service = $this->createServiceWithMockClient([]);
        $service->monitorCase($case);

        $this->em->refresh($case);
        $this->assertSame(0, $case->getPortalConsecutiveFailures());
        $this->assertTrue($case->isPortalMonitoringActive());
    }

    public function testMonitorCaseBelowThresholdDoesNotStopOrNotify(): void
    {
        $case = $this->createSubmittedCase();
        $this->setPortalFailures($case, 2);

        $service = $this->createServiceWithThrowingClient();
        $spy = $this->injectDispatcherSpy($service);

        try {
            $service->monitorCase($case);
            $this->fail('Expected PortalJustException below the failure threshold');
        } catch (PortalJustException) {
            // expected
        }

        $this->em->refresh($case);
        $this->assertSame(3, $case->getPortalConsecutiveFailures());
        $this->assertTrue($case->isPortalMonitoringActive());
        $this->assertCount(0, $spy->dispatches);
    }

    public function testMonitorCaseStopsMonitoringAndNotifiesOnFifthFailure(): void
    {
        $case = $this->createSubmittedCase();
        $this->setPortalFailures($case, 4);

        $service = $this->createServiceWithThrowingClient();
        $spy = $this->injectDispatcherSpy($service);

        // The fifth consecutive failure stops the retry chain: returns instead
        // of re-throwing.
        $this->assertSame(0, $service->monitorCase($case));

        $this->em->refresh($case);
        $this->assertSame(5, $case->getPortalConsecutiveFailures());
        $this->assertFalse($case->isPortalMonitoringActive());

        $this->assertCount(1, $spy->dispatches);
        $dispatch = $spy->dispatches[0];
        $this->assertSame(NotificationType::PORTAL_QUERY_FAILED, $dispatch->type);
        $this->assertSame('error', $dispatch->variant);
        $this->assertSame($this->user->getId(), $dispatch->user->getId());
        $this->assertSame($case->getId(), $dispatch->legalCase->getId());
    }

    public function testMonitorCasePersistsPortalQueryFailedNotificationOnDeactivation(): void
    {
        $case = $this->createSubmittedCase();
        $this->setPortalFailures($case, 4);

        // Real dispatcher from the container: assert the durable in-app row.
        $service = $this->createServiceWithThrowingClient();

        $this->assertSame(0, $service->monitorCase($case));

        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'portal_query_failed',
        ]);
        $this->assertCount(1, $notifications);
        $this->assertSame($this->user->getId(), $notifications[0]->getUser()->getId());
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE al FROM audit_log al WHERE al.entity_id IN (SELECT lc.id FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?)",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE al FROM audit_log al JOIN legal_deadline ld ON al.entity_id = ld.id AND al.entity_type = 'App\\\\Entity\\\\LegalDeadline' JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE cpe FROM court_portal_event cpe JOIN legal_case lc ON cpe.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE ?", ['%' . $this->testPrefix . '%']);
        parent::tearDown();
    }
}
