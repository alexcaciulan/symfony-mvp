<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\AuditLog;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Service\AuditLogService;
use App\Service\Case\CaseFullPaymentClosureService;
use App\Service\Case\CaseWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CaseFullPaymentClosureServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->user = new User();
        $this->user->setEmail('full-payment-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id OR user_id IS NULL', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM notification WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    private function caseWithDeadlines(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $this->em->persist($case);

        foreach ([DeadlineType::PRESCRIPTIE, DeadlineType::OTHER] as $type) {
            $deadline = new LegalDeadline();
            $deadline->setLegalCase($case);
            $deadline->setType($type);
            $deadline->setDeadlineDate(new \DateTimeImmutable('+30 days'));
            $deadline->setPriority(DeadlinePriority::MEDIUM);
            $this->em->persist($deadline);
        }
        $this->em->flush();

        return $case;
    }

    private function openDeadlines(int $caseId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM legal_deadline WHERE legal_case_id = :id AND completed = 0',
            ['id' => $caseId],
        );
    }

    public function testRefusesOnceThePaymentOrderRequestIsGenerated(): void
    {
        $case = $this->caseWithDeadlines(CaseStatus::CERERE_GENERATA);
        $service = static::getContainer()->get(CaseFullPaymentClosureService::class);

        $this->expectException(\DomainException::class);
        try {
            $service->close($case, $this->user, new \DateTimeImmutable('2026-01-10'), null, null);
        } finally {
            self::assertSame(CaseStatus::CERERE_GENERATA, $case->getStatus());
            self::assertSame(2, $this->openDeadlines($case->getId()));
        }
    }

    /** Never a closed case with its limitation term still open: one failure undoes it all. */
    public function testAFailureRollsBackStatusAndDeadlines(): void
    {
        $case = $this->caseWithDeadlines(CaseStatus::SOMATIE_TRIMISA);
        $caseId = $case->getId();
        $container = static::getContainer();

        $audit = $this->createStub(AuditLogService::class);
        // Fails on the very last write, after the status and every deadline have changed.
        $audit->method('log')->willReturnCallback(static function (string $action): AuditLog {
            if ($action === 'case_closed') {
                throw new \RuntimeException('audit store down');
            }

            return new AuditLog();
        });
        $service = new CaseFullPaymentClosureService(
            $this->em,
            $container->get(CaseWorkflowService::class),
            $container->get('test.public.deadline_service'),
            $audit,
        );

        try {
            $service->close($case, $this->user, new \DateTimeImmutable('2026-01-10'), null, null);
            self::fail('The audit failure must surface.');
        } catch (\RuntimeException) {
        }

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM legal_case WHERE id = :id', ['id' => $caseId]);
        self::assertSame('SOMATIE_TRIMISA', $status);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT full_payment_date FROM legal_case WHERE id = :id', ['id' => $caseId]));
        self::assertSame(2, $this->openDeadlines($caseId));
    }
}
