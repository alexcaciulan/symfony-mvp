<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AuditLog;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 4.0.1 — Tests for AuditLogRepository::findByCase().
 *
 * Verifies the new finder filters by `entityType = LegalCase::class FQN` + `entityId`
 * (matching the persistence convention used by CaseWizardController/AuditLogService),
 * orders DESC by createdAt, and respects the limit.
 */
class AuditLogRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditLogRepository $repo;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(AuditLog::class);
        $this->testPrefix = 'audit-repo-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    private function createCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus(CaseStatus::AMIABIL);
        $this->em->persist($case);

        return $case;
    }

    private function createAuditLog(LegalCase $case, string $action = 'test_action', ?\DateTimeImmutable $createdAt = null): AuditLog
    {
        $log = new AuditLog();
        $log->setUser($this->user);
        $log->setAction($action);
        $log->setEntityType(LegalCase::class);
        $log->setEntityId((string) $case->getId());

        if ($createdAt !== null) {
            // The createdAt column is DATETIME (second precision) and the entity sets it
            // in the constructor — use reflection to force a known timestamp so that
            // ordering tests don't depend on sub-second flush timing.
            $ref = new \ReflectionProperty(AuditLog::class, 'createdAt');
            $ref->setValue($log, $createdAt);
        }

        $this->em->persist($log);

        return $log;
    }

    public function testFindByCaseReturnsOnlyEntriesForCase(): void
    {
        $case1 = $this->createCase();
        $case2 = $this->createCase();
        $this->em->flush();

        $this->createAuditLog($case1, 'action_a');
        $this->createAuditLog($case1, 'action_b');
        $this->createAuditLog($case2, 'action_c');
        $this->em->flush();

        $result = $this->repo->findByCase($case1);

        self::assertCount(2, $result);
        foreach ($result as $log) {
            self::assertSame(LegalCase::class, $log->getEntityType());
            self::assertSame((string) $case1->getId(), $log->getEntityId());
        }
    }

    public function testFindByCaseReturnsOrderedDescByCreatedAt(): void
    {
        $case = $this->createCase();
        $this->em->flush();

        $first = $this->createAuditLog($case, 'first', new \DateTimeImmutable('-30 minutes'));
        $second = $this->createAuditLog($case, 'second', new \DateTimeImmutable('-15 minutes'));
        $third = $this->createAuditLog($case, 'third', new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $result = $this->repo->findByCase($case);

        self::assertCount(3, $result);
        self::assertSame($third->getId(), $result[0]->getId(), 'Most recent first');
        self::assertSame($second->getId(), $result[1]->getId());
        self::assertSame($first->getId(), $result[2]->getId(), 'Oldest last');
    }

    public function testFindByCaseRespectsLimit(): void
    {
        $case = $this->createCase();
        $this->em->flush();

        for ($i = 0; $i < 5; $i++) {
            $this->createAuditLog($case, "action_{$i}");
        }
        $this->em->flush();

        $result = $this->repo->findByCase($case, 2);

        self::assertCount(2, $result);
    }
}
