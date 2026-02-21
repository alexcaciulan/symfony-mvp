<?php

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repo;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(User::class);
        $this->testPrefix = 'repo-user-' . uniqid();
    }

    private function createUser(bool $verified = true, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($this->testPrefix . '-' . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified($verified);
        if ($roles) {
            $user->setRoles($roles);
        }
        $this->em->persist($user);

        return $user;
    }

    public function testCountAllReturnsCorrectNumber(): void
    {
        $countBefore = $this->repo->countAll();

        $this->createUser();
        $this->createUser();
        $this->em->flush();

        $this->assertSame($countBefore + 2, $this->repo->countAll());
    }

    public function testCountVerifiedFiltersCorrectly(): void
    {
        $verifiedBefore = $this->repo->countVerified();

        $this->createUser(true);
        $this->createUser(false);
        $this->em->flush();

        $this->assertSame($verifiedBefore + 1, $this->repo->countVerified());
    }

    public function testCountUnverifiedFiltersCorrectly(): void
    {
        $unverifiedBefore = $this->repo->countUnverified();

        $this->createUser(true);
        $this->createUser(false);
        $this->em->flush();

        $this->assertSame($unverifiedBefore + 1, $this->repo->countUnverified());
    }

    public function testCountAdminsFiltersRolesArray(): void
    {
        $adminsBefore = $this->repo->countAdmins();

        $this->createUser(true, ['ROLE_ADMIN']);
        $this->createUser(true); // regular user
        $this->em->flush();

        $this->assertSame($adminsBefore + 1, $this->repo->countAdmins());
    }

    public function testUpgradePasswordPersistsToDatabase(): void
    {
        $user = $this->createUser();
        $this->em->flush();

        $oldPassword = $user->getPassword();
        $this->repo->upgradePassword($user, 'new_hashed_password');

        $this->em->clear();
        $refreshed = $this->repo->find($user->getId());
        $this->assertSame('new_hashed_password', $refreshed->getPassword());
        $this->assertNotSame($oldPassword, $refreshed->getPassword());
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement(
            "DELETE FROM user WHERE email LIKE ?",
            [$this->testPrefix . '%']
        );
        parent::tearDown();
    }
}
