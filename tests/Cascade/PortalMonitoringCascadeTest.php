<?php

declare(strict_types=1);

namespace App\Tests\Cascade;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\CourtType;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Portal\CaseMonitoringService;
use App\Service\Portal\PortalJustClient;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 6.1 Nivel 3 cascade — flux end-to-end pe fixturi SOAP realiste:
 * monitorCase → PortalEventDetector → persist CourtPortalEvent + Notification +
 * AuditLog → MonitoringEventApplier → tranziție workflow + termen / propunere.
 */
class PortalMonitoringCascadeTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private LegalDeadlineRepository $deadlines;
    private User $user;
    private Court $court;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->deadlines = static::getContainer()->get(LegalDeadlineRepository::class);
        $this->testPrefix = 'portal-cascade-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->court = new Court();
        $this->court->setName('Cascade Court ' . $this->testPrefix);
        $this->court->setCounty($this->createCounty($this->em, 'CJ'));
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setPortalCode('CascadeCourt' . uniqid());
        $this->em->persist($this->court);

        $this->em->flush();
    }

    private function createCase(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCourt($this->court);
        $case->setStatus($status);
        $case->setCourtCaseNumber('1234/211/2026');
        $case->setPortalMonitoringActive(true);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    /**
     * @param array<int, array<string, mixed>> $sedinte
     * @param array<int, array<string, mixed>> $caiAtac
     */
    private function serviceReturning(array $sedinte = [], array $caiAtac = []): CaseMonitoringService
    {
        $soapResponse = [[
            'numar' => '1234/211/2026',
            'institutie' => 'Cascade',
            'departament' => null,
            'categorieCaz' => null,
            'stadiuProcesual' => null,
            'obiect' => null,
            'dataModificare' => null,
            'parti' => [],
            'sedinte' => $sedinte,
            'caiAtac' => $caiAtac,
        ]];

        $mockClient = $this->createStub(PortalJustClient::class);
        $mockClient->method('searchByCaseNumber')->willReturn($soapResponse);

        $service = static::getContainer()->get(CaseMonitoringService::class);
        (new \ReflectionClass($service))->getProperty('portalClient')->setValue($service, $mockClient);

        return $service;
    }

    public function testHearingScheduledCascadesToTermenFixatAndDeadline(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);

        $service = $this->serviceReturning(sedinte: [
            ['data' => '15.09.2026', 'complet' => 'C12', 'ora' => '09:00', 'solutie' => null, 'solutieSumar' => null, 'dataPronuntare' => null],
        ]);

        $newCount = $service->monitorCase($case);

        $this->assertSame(1, $newCount);

        // Eveniment + notificare + audit detectare.
        $events = $this->em->getRepository(CourtPortalEvent::class)->findBy(['legalCase' => $case]);
        $this->assertCount(1, $events);
        $this->assertCount(1, $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'portal_update',
        ]));

        // Tranziție AUTO aplicată + termen JUDECATA creat de applier.
        $this->assertSame(CaseStatus::TERMEN_FIXAT, $case->getStatus());
        $this->assertNotNull($this->deadlines->findOneByCaseAndType($case, DeadlineType::JUDECATA));

        $applied = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => 'portal_transition_applied',
        ]);
        $this->assertNotEmpty($applied);
        $this->assertSame(CaseTransition::FIXEAZA_TERMEN->value, $applied[0]->getNewData()['transition']);
    }

    public function testRulingWithSolutionStaysProposalOnlyUnderConservativePolicy(): void
    {
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);

        $service = $this->serviceReturning(sedinte: [
            [
                'data' => '20.09.2026',
                'complet' => 'C12',
                'ora' => '11:00',
                'solutie' => 'Admite cererea. Emite ordonanța de plată.',
                'solutieSumar' => 'Admisă',
                'dataPronuntare' => '20.09.2026',
            ],
        ]);

        $service->monitorCase($case);

        // Conservator: emite_ordonanta NU se aplică automat din text liber.
        $this->assertSame(CaseStatus::TERMEN_FIXAT, $case->getStatus());

        $proposals = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => 'portal_transition_proposed',
        ]);
        $this->assertNotEmpty($proposals);
        $this->assertSame(
            CaseTransition::EMITE_ORDONANTA->value,
            $proposals[0]->getNewData()['suggestedTransition'],
        );
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
