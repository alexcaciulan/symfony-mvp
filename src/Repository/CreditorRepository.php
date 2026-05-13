<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Creditor;
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
}
