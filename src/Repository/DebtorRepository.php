<?php

namespace App\Repository;

use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\PersonType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Debtor> */
class DebtorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Debtor::class);
    }

    /**
     * The lawyer's company with this canonical CUI, if any. Rows migrated from
     * before the library may share a key, so the most recent one answers.
     */
    public function findOneByUserAndCuiKey(User $user, string $cuiKey, ?Debtor $except = null): ?Debtor
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.cuiKey = :key')
            ->setParameter('user', $user)
            ->setParameter('key', $cuiKey)
            ->orderBy('d.updatedAt', 'DESC')
            ->setMaxResults(1);
        if ($except !== null && $except->getId() !== null) {
            $qb->andWhere('d.id != :except')->setParameter('except', $except->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * The companies the library shows: the lawyer's legal persons with a CUI.
     * Natural persons from before the PJ-only rule stay on their cases only.
     */
    public function createLibraryQueryBuilder(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.personType = :pj')
            ->andWhere('d.cuiKey IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('pj', PersonType::PJ);
    }

    /** The wizard picker: the library, most recently touched first. */
    public function createAutocompleteQueryBuilder(User $user): QueryBuilder
    {
        return $this->createLibraryQueryBuilder($user)
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.name', 'ASC');
    }

    /** The lawyer's own company by id, or null (another lawyer's reads as absent). */
    public function findOwned(User $user, int $id): ?Debtor
    {
        $debtor = $this->find($id);

        return $debtor !== null && $debtor->getUser()->getId() === $user->getId() ? $debtor : null;
    }

    /**
     * The cases the company appears in, most recent first, soft-deleted ones
     * left out.
     *
     * @return list<LegalCase>
     */
    public function casesUsing(Debtor $debtor): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('lc')
            ->from(LegalCase::class, 'lc')
            ->join('lc.debtors', 'link')
            ->andWhere('link.debtor = :debtor')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('debtor', $debtor)
            ->orderBy('lc.createdAt', 'DESC')
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    /** Cases naming the company, soft-deleted ones left out. */
    public function countActiveCases(Debtor $debtor): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT lc.id)')
            ->from(LegalCaseDebtor::class, 'link')
            ->join('link.legalCase', 'lc')
            ->andWhere('link.debtor = :debtor')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('debtor', $debtor)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Every link, soft-deleted cases included: those still hold the company. */
    public function countLinks(Debtor $debtor): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(link.id)')
            ->from(LegalCaseDebtor::class, 'link')
            ->andWhere('link.debtor = :debtor')
            ->setParameter('debtor', $debtor)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
