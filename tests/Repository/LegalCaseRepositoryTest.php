<?php

namespace App\Tests\Repository;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Repository\LegalCaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LegalCaseRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LegalCaseRepository $repo;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(LegalCase::class);
        $this->testPrefix = 'repo-case-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createCase(string $status = 'draft', bool $deleted = false): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $case->setCurrentStep(1);
        if ($deleted) {
            $case->setDeletedAt(new \DateTimeImmutable());
        }
        $this->em->persist($case);

        return $case;
    }

    public function testFindByUserReturnsOnlyUserCases(): void
    {
        $this->createCase();
        $this->createCase();

        // Create another user with a case
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail($this->testPrefix . '-other@test.com');
        $other->setPassword($hasher->hashPassword($other, 'test'));
        $other->setIsVerified(true);
        $this->em->persist($other);

        $otherCase = new LegalCase();
        $otherCase->setUser($other);
        $otherCase->setStatus('draft');
        $otherCase->setCurrentStep(1);
        $this->em->persist($otherCase);
        $this->em->flush();

        $result = $this->repo->findByUser($this->user);
        $this->assertCount(2, $result);
        foreach ($result as $case) {
            $this->assertSame($this->user->getId(), $case->getUser()->getId());
        }
    }

    public function testFindByUserExcludesSoftDeleted(): void
    {
        $this->createCase('draft', false);
        $this->createCase('draft', true);
        $this->em->flush();

        $result = $this->repo->findByUser($this->user);
        $this->assertCount(1, $result);
    }

    public function testFindByUserOrdersByCreatedAtDesc(): void
    {
        $case1 = $this->createCase();
        $this->em->flush();

        // Small delay to ensure different timestamps
        usleep(10000);

        $case2 = $this->createCase();
        $this->em->flush();

        $result = $this->repo->findByUser($this->user);
        $this->assertCount(2, $result);
        $this->assertGreaterThanOrEqual(
            $result[1]->getCreatedAt()->getTimestamp(),
            $result[0]->getCreatedAt()->getTimestamp()
        );
    }

    public function testCountAllExcludesSoftDeleted(): void
    {
        $countBefore = $this->repo->countAll();
        $this->createCase('draft', false);
        $this->createCase('draft', true);
        $this->em->flush();

        $this->assertSame($countBefore + 1, $this->repo->countAll());
    }

    public function testCountByStatusFiltersByStatusAndExcludesSoftDeleted(): void
    {
        $draftsBefore = $this->repo->countByStatus('draft');
        $paidBefore = $this->repo->countByStatus('paid');

        $this->createCase('draft');
        $this->createCase('draft', true); // soft-deleted draft
        $this->createCase('paid');
        $this->em->flush();

        $this->assertSame($draftsBefore + 1, $this->repo->countByStatus('draft'));
        $this->assertSame($paidBefore + 1, $this->repo->countByStatus('paid'));
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
