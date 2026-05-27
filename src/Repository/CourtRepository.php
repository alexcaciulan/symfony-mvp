<?php

namespace App\Repository;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Court> */
class CourtRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Court::class);
    }

    /** @return Court[] */
    public function findActiveByCounty(string $county): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.county = :county')
            ->andWhere('c.active = true')
            ->setParameter('county', $county)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Court[] */
    public function findActiveByTypeAndCounty(CourtType $type, string $county): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.type = :type')
            ->andWhere('c.county = :county')
            ->andWhere('c.active = true')
            ->setParameter('type', $type)
            ->setParameter('county', $county)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Courts the given user actually has cases in, matching `$query` on name.
     * Used by the `/cases` filter autocomplete: avoids surfacing courts the
     * user has no cases in (an empty filter result would be confusing).
     *
     * @return list<array{value: int, label: string}>
     */
    public function findForUserAutocomplete(User $user, string $query, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id AS value, c.name AS label')
            ->innerJoin(LegalCase::class, 'lc', 'WITH', 'lc.court = c.id')
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

    /** @return string[] */
    public function findDistinctCounties(): array
    {
        return array_column(
            $this->createQueryBuilder('c')
                ->select('DISTINCT c.county')
                ->where('c.active = true')
                ->orderBy('c.county', 'ASC')
                ->getQuery()
                ->getScalarResult(),
            'county'
        );
    }
}
