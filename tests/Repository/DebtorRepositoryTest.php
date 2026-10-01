<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Debtor;
use App\Entity\User;
use App\Enum\PersonType;
use App\Repository\DebtorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DebtorRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DebtorRepository $repo;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(DebtorRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->userIds as $id) {
            $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testThePickerOffersOnlyTheLawyersOwnLegalPersonsWithACui(): void
    {
        $mine = $this->user();
        $this->debtor($mine, 'Firma Mea SRL', 'RO15193236');
        $this->debtor($mine, 'Fără CUI', null);
        $this->debtor($mine, 'Ion Popescu', null, PersonType::PF);
        $this->debtor($this->user(), 'Firma Altuia SRL', 'RO14186770');
        $this->em->flush();

        $names = array_map(static fn (Debtor $d): string => $d->getName(), $this->repo->createAutocompleteQueryBuilder($mine)->getQuery()->getResult());

        self::assertSame(['Firma Mea SRL'], $names);
    }

    public function testAnotherLawyersCompanyReadsAsAbsent(): void
    {
        $other = $this->debtor($this->user(), 'Firma Altuia SRL', 'RO14186770');
        $this->em->flush();

        self::assertNull($this->repo->findOwned($this->user(), (int) $other->getId()));
    }

    private function user(): User
    {
        $user = (new User())->setEmail('debtor-repo-' . uniqid() . '@test.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    private function debtor(User $user, string $name, ?string $cui, PersonType $type = PersonType::PJ): Debtor
    {
        $debtor = (new Debtor())->setUser($user)->setPersonType($type)->setName($name)->setCui($cui)->setAddress('Str. X 1');
        $this->em->persist($debtor);

        return $debtor;
    }
}
