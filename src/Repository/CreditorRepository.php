<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Creditor> */
class CreditorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Creditor::class);
    }

    /** @return Creditor[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pas 3.3 — scoped query builder for the UX Autocomplete field on Step 1.
     * Tom Select fetches results from this builder, so the user only ever sees
     * creditors from their own library (UNIQUE(user, cui) on the entity).
     */
    public function createAutocompleteQueryBuilder(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.name', 'ASC');
    }

    /**
     * Remote source for the cases-list creditor filter (Tom Select `load`).
     * Scoped to creditors that actually appear in the user's cases, so the
     * dropdown never lists creditors that would produce zero filter hits. The
     * `q` query is matched against the creditor name (case-insensitive LIKE),
     * capped at $limit results.
     *
     * @return list<array{value: int, label: string}>
     */
    public function findForUserAutocomplete(User $user, string $query, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id AS value, c.name AS label')
            ->innerJoin(LegalCase::class, 'lc', 'WITH', 'lc.creditor = c.id')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->groupBy('c.id, c.name')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit);

        if ($query !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q')
                ->setParameter('q', '%' . addcslashes(mb_strtolower($query), '\\%_') . '%');
        }

        /** @var list<array{value: int, label: string}> */
        return $qb->getQuery()->getArrayResult();
    }
}
