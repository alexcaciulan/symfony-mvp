<?php

namespace App\Tests\Service\Portal;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CourtType;
use App\Enum\PortalEventType;
use App\Service\Portal\CaseMonitoringService;
use App\Service\Portal\PortalJustClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CaseMonitoringServiceTest extends KernelTestCase
{
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
        $this->court->setCounty('CJ');
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
        $case->setStatus('submitted_to_court');
        $case->setCaseNumber('200/211/2026');
        $case->setCurrentStep(6);
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

        // Verify notification was created
        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'portal_update',
        ]);
        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('200/211/2026', $notifications[0]->getTitle());

        // Verify lastPortalCheckAt was updated
        $this->em->refresh($case);
        $this->assertNotNull($case->getLastPortalCheckAt());
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
        $case->setStatus('submitted_to_court');
        $case->setCaseNumber('300/2026');
        $case->setCurrentStep(6);
        $this->em->persist($case);
        $this->em->flush();

        $service = static::getContainer()->get(CaseMonitoringService::class);
        $newCount = $service->monitorCase($case);

        $this->assertSame(0, $newCount);
    }

    public function testMonitorCaseSkipsCaseWithoutPortalCode(): void
    {
        $courtNoCode = new Court();
        $courtNoCode->setName('No Portal ' . $this->testPrefix);
        $courtNoCode->setCounty('CJ');
        $courtNoCode->setType(CourtType::JUDECATORIE);
        $this->em->persist($courtNoCode);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCourt($courtNoCode);
        $case->setStatus('submitted_to_court');
        $case->setCaseNumber('400/2026');
        $case->setCurrentStep(6);
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

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE al FROM audit_log al WHERE al.entity_id IN (SELECT lc.id FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?)",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?",
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
