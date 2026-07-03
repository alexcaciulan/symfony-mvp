<?php

namespace App\Tests\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\CaseStatusHistory;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Service\Case\CaseWorkflowService;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CaseWorkflowSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseWorkflowService $workflowService;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->workflowService = static::getContainer()->get(CaseWorkflowService::class);

        // Pasul 1.4 livrează migrarea baseline pentru noul schema LexRecovery.
        // Dacă tabelul nu reflectă încă noile coloane (creditor_id, status enum etc.),
        // sărim testele DB-dependente — vor rula după Pasul 1.4.
        $schema = $this->em->getConnection()->createSchemaManager();
        if (!$schema->tablesExist(['legal_case', 'creditor'])) {
            $this->markTestSkipped('LexRecovery baseline migration not yet applied (Pasul 1.4).');
        }

        $this->testPrefix = 'subscriber-test-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        // Initial state machine marking = AMIABIL (entity default)
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testCreatesStatusHistoryOnTransition(): void
    {
        $case = $this->createCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $histories = $this->em->getRepository(CaseStatusHistory::class)->findBy(['legalCase' => $caseId]);
        $this->assertNotEmpty($histories);

        $history = $histories[0];
        $this->assertSame(CaseStatus::AMIABIL->value, $history->getOldStatus());
        $this->assertSame(CaseStatus::SOMATIE_TRIMISA->value, $history->getNewStatus());
    }

    public function testCreatesAuditLogOnTransition(): void
    {
        $case = $this->createCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => 'LegalCase',
            'entityId' => (string) $caseId,
            'action' => 'case_status_change',
        ]);
        $this->assertNotEmpty($logs);

        $log = $logs[0];
        $this->assertSame('case_status_change', $log->getAction());
        $this->assertSame(['status' => CaseStatus::AMIABIL->value], $log->getOldData());
        $this->assertSame(['status' => CaseStatus::SOMATIE_TRIMISA->value], $log->getNewData());
    }

    public function testAuditLogEntityTypeIsLegalCase(): void
    {
        $case = $this->createCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityId' => (string) $caseId,
            'action' => 'case_status_change',
        ]);
        $this->assertSame('LegalCase', $log->getEntityType());
    }

    public function testMultipleTransitionsCreateMultipleEntries(): void
    {
        $case = $this->createCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $this->workflowService->apply($case, 'depune_cerere');
        $this->em->flush();

        $histories = $this->em->getRepository(CaseStatusHistory::class)->findBy(['legalCase' => $caseId]);
        $this->assertCount(2, $histories);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => 'LegalCase',
            'entityId' => (string) $caseId,
            'action' => 'case_status_change',
        ]);
        $this->assertCount(2, $logs);
    }

    public function testStatusHistoryHasNullUserInKernelContext(): void
    {
        $case = $this->createCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $history = $this->em->getRepository(CaseStatusHistory::class)->findOneBy(['legalCase' => $caseId]);
        $this->assertNull($history->getCreatedBy());
    }

    public function testSubscriberListensToCorrectEvent(): void
    {
        $events = \App\EventSubscriber\CaseWorkflowSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey('workflow.legal_case.completed', $events);
        $this->assertSame('onCompleted', $events['workflow.legal_case.completed']);
    }

    public function testMonitoringAutoStopsWhenEnteringDefinitiva(): void
    {
        $case = $this->createCase();
        $case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $case->setPortalMonitoringActive(true);
        $this->em->flush();

        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        $this->assertFalse($case->isPortalMonitoringActive(), 'DEFINITIVA leaves the monitorable set → monitoring off.');

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityId' => (string) $case->getId(),
            'action' => 'portal_monitoring_auto_stopped',
        ]);
        $this->assertNotNull($log);
    }

    public function testMonitoringAutoStopsWhenRejected(): void
    {
        $case = $this->createCase();
        $case->setStatus(CaseStatus::TERMEN_FIXAT);
        $case->setPortalMonitoringActive(true);
        $this->em->flush();

        $this->workflowService->apply($case, 'respinge');
        $this->em->flush();

        $this->assertFalse($case->isPortalMonitoringActive());

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityId' => (string) $case->getId(),
            'action' => 'portal_monitoring_auto_stopped',
        ]);
        $this->assertNotNull($log);
    }

    public function testMonitoringStaysActiveWithinMonitorableSet(): void
    {
        $case = $this->createCase();
        $case->setStatus(CaseStatus::DOSAR_INREGISTRAT);
        $case->setPortalMonitoringActive(true);
        $this->em->flush();

        $this->workflowService->apply($case, 'fixeaza_termen'); // → TERMEN_FIXAT (still monitorable)
        $this->em->flush();

        $this->assertTrue($case->isPortalMonitoringActive(), 'Transition within the monitorable set must not stop monitoring.');

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityId' => (string) $case->getId(),
            'action' => 'portal_monitoring_auto_stopped',
        ]);
        $this->assertNull($log, 'No auto-stop audit when staying monitorable.');
    }

    public function testNoAutoStopAuditWhenAlreadyInactive(): void
    {
        $case = $this->createCase();
        $case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $case->setPortalMonitoringActive(false);
        $this->em->flush();

        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        $this->assertFalse($case->isPortalMonitoringActive());
        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityId' => (string) $case->getId(),
            'action' => 'portal_monitoring_auto_stopped',
        ]);
        $this->assertNull($log, 'Flag already false → no redundant auto-stop audit.');
    }

    protected function tearDown(): void
    {
        if (!isset($this->testPrefix)) {
            parent::tearDown();
            return;
        }

        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement(
                "DELETE al FROM audit_log al WHERE al.entity_type = 'LegalCase' AND al.entity_id IN (SELECT lc.id FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?)",
                [$this->testPrefix . '%']
            );
            $conn->executeStatement(
                "DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
                [$this->testPrefix . '%']
            );
            $conn->executeStatement(
                "DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?",
                [$this->testPrefix . '%']
            );
            // marcheaza_definitiva creates a PRESCRIPTIE_EXECUTARE deadline (R1),
            // so deadlines must be removed before the parent legal_case rows.
            $conn->executeStatement(
                "DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
                [$this->testPrefix . '%']
            );
            $conn->executeStatement(
                "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
                [$this->testPrefix . '%']
            );
            $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        } catch (TableNotFoundException) {
            // Schema baseline not yet present (pre-Pasul 1.4) — nothing to clean.
        }
        parent::tearDown();
    }
}
