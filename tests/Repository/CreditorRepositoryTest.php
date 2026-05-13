<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Creditor;
use App\Entity\User;
use App\Enum\PersonType;
use App\Repository\CreditorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 3.3 — `createAutocompleteQueryBuilder` is the contract that Tom Select
 * (UX Autocomplete) uses to fetch creditor suggestions in Step 1. The whole
 * point is that the dropdown is scoped to the current lawyer — these tests
 * verify nothing leaks across users.
 */
final class CreditorRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreditorRepository $repo;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(Creditor::class);
        $this->testPrefix = 'creditor-repo-' . uniqid();
    }

    public function testAutocompleteQueryBuilderScopedToUser(): void
    {
        $userA = $this->createUser('a');
        $userB = $this->createUser('b');

        $this->createCreditor($userA, 'Alpha SRL', 'RO10000010');
        $this->createCreditor($userA, 'Beta SRL', 'RO10000028');
        $this->createCreditor($userB, 'Other Lawyer Creditor', 'RO10000036');
        $this->em->flush();

        $resultA = $this->repo
            ->createAutocompleteQueryBuilder($userA)
            ->getQuery()
            ->getResult();

        self::assertCount(2, $resultA);
        foreach ($resultA as $creditor) {
            self::assertSame($userA->getId(), $creditor->getUser()->getId());
        }

        $resultB = $this->repo
            ->createAutocompleteQueryBuilder($userB)
            ->getQuery()
            ->getResult();

        self::assertCount(1, $resultB);
        self::assertSame('Other Lawyer Creditor', $resultB[0]->getName());
    }

    public function testAutocompleteQueryBuilderReturnsEmptyForUserWithoutCreditors(): void
    {
        $user = $this->createUser('lonely');
        $this->em->flush();

        $result = $this->repo
            ->createAutocompleteQueryBuilder($user)
            ->getQuery()
            ->getResult();

        self::assertSame([], $result);
    }

    public function testAutocompleteQueryBuilderOrdersByNameAsc(): void
    {
        $user = $this->createUser('order');
        $this->createCreditor($user, 'Zeta SRL', 'RO10000044');
        $this->createCreditor($user, 'Alpha SRL', 'RO10000052');
        $this->createCreditor($user, 'Mu SRL', 'RO10000060');
        $this->em->flush();

        $names = array_map(
            static fn (Creditor $c): string => $c->getName(),
            $this->repo->createAutocompleteQueryBuilder($user)->getQuery()->getResult(),
        );

        self::assertSame(['Alpha SRL', 'Mu SRL', 'Zeta SRL'], $names);
    }

    private function createUser(string $suffix): User
    {
        $user = new User();
        $user->setEmail($this->testPrefix . '-' . $suffix . '@test.com');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function createCreditor(User $user, string $name, string $cui): Creditor
    {
        $creditor = new Creditor();
        $creditor->setUser($user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName($name);
        $creditor->setCui($cui);
        $creditor->setAddress('Str. Test nr. 1, București');
        $this->em->persist($creditor);

        return $creditor;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE c FROM creditor c JOIN user u ON c.user_id = u.id WHERE u.email LIKE ?',
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            'DELETE FROM user WHERE email LIKE ?',
            [$this->testPrefix . '%'],
        );
        parent::tearDown();
    }
}
