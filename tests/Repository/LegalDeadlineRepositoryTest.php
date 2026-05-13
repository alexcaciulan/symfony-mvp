<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 4.0.1 — Tests for LegalDeadlineRepository::findByCase().
 *
 * Verifies the new finder scopes results to a single case and orders ascending
 * by `deadlineDate` (matches the OneToMany OrderBy on LegalCase.deadlines).
 */
class LegalDeadlineRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LegalDeadlineRepository $repo;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(LegalDeadline::class);
        $this->testPrefix = 'deadline-repo-' . uniqid();

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
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
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

    private function createDeadline(LegalCase $case, \DateTimeImmutable $date, DeadlineType $type = DeadlineType::RASPUNS_SOMATIE): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate($date);
        $deadline->setPriority(DeadlinePriority::MEDIUM);
        $this->em->persist($deadline);

        return $deadline;
    }

    public function testFindByCaseReturnsOnlyForCase(): void
    {
        $case1 = $this->createCase();
        $case2 = $this->createCase();
        $this->em->flush();

        $this->createDeadline($case1, new \DateTimeImmutable('+5 days'));
        $this->createDeadline($case1, new \DateTimeImmutable('+10 days'));
        $this->createDeadline($case2, new \DateTimeImmutable('+7 days'));
        $this->em->flush();

        $result = $this->repo->findByCase($case1);

        self::assertCount(2, $result);
        foreach ($result as $deadline) {
            self::assertSame($case1->getId(), $deadline->getLegalCase()->getId());
        }
    }

    public function testFindByCaseReturnsOrderedAscByDeadlineDate(): void
    {
        $case = $this->createCase();
        $this->em->flush();

        $this->createDeadline($case, new \DateTimeImmutable('+15 days'));
        $this->createDeadline($case, new \DateTimeImmutable('+3 days'));
        $this->createDeadline($case, new \DateTimeImmutable('+9 days'));
        $this->em->flush();

        $result = $this->repo->findByCase($case);

        self::assertCount(3, $result);
        $previousTimestamp = null;
        foreach ($result as $deadline) {
            $current = $deadline->getDeadlineDate()->getTimestamp();
            if ($previousTimestamp !== null) {
                self::assertGreaterThanOrEqual($previousTimestamp, $current, 'Deadlines must be ordered ASC by deadlineDate');
            }
            $previousTimestamp = $current;
        }
    }
}
