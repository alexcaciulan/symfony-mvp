<?php

namespace App\Tests\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\CaseStatusHistory;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Service\Case\CaseWorkflowService;
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
        $this->testPrefix = 'subscriber-test-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createDraftCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus('draft');
        $case->setCurrentStep(1);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testCreatesStatusHistoryOnTransition(): void
    {
        $case = $this->createDraftCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'submit');
        $this->em->flush();

        $histories = $this->em->getRepository(CaseStatusHistory::class)->findBy(['legalCase' => $caseId]);
        $this->assertNotEmpty($histories);

        $history = $histories[0];
        $this->assertSame('draft', $history->getOldStatus());
        $this->assertSame('pending_payment', $history->getNewStatus());
    }

    public function testCreatesAuditLogOnTransition(): void
    {
        $case = $this->createDraftCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'submit');
        $this->em->flush();

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => 'LegalCase',
            'entityId' => (string) $caseId,
            'action' => 'case_status_change',
        ]);
        $this->assertNotEmpty($logs);

        $log = $logs[0];
        $this->assertSame('case_status_change', $log->getAction());
        $this->assertSame(['status' => 'draft'], $log->getOldData());
        $this->assertSame(['status' => 'pending_payment'], $log->getNewData());
    }

    public function testAuditLogEntityTypeIsLegalCase(): void
    {
        $case = $this->createDraftCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'submit');
        $this->em->flush();

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityId' => (string) $caseId,
            'action' => 'case_status_change',
        ]);
        $this->assertSame('LegalCase', $log->getEntityType());
    }

    public function testMultipleTransitionsCreateMultipleEntries(): void
    {
        $case = $this->createDraftCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'submit');
        $this->em->flush();

        $this->workflowService->apply($case, 'confirm_payment');
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
        // In KernelTestCase without authenticated user, createdBy should be null
        $case = $this->createDraftCase();
        $caseId = $case->getId();

        $this->workflowService->apply($case, 'submit');
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

    protected function tearDown(): void
    {
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
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
