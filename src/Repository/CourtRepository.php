<?php

namespace App\Repository;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtType;
use App\Service\Court\LocalityNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Court> */
class CourtRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Court::class);
    }

    /**
     * Search ALL active courts by name (load-on-type source for the step-4
     * competent-court Tom Select). Unlike {@see findForUserAutocomplete()} it is
     * NOT scoped to the user's existing cases: a brand-new case may need any
     * court in the country. Label carries the county for disambiguation.
     *
     * @return list<array{value: int, label: string}>
     */
    public function searchActiveByName(string $query, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id AS value', 'c.name AS name', 'co.name AS county')
            ->join('c.county', 'co')
            ->where('c.active = true')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit);

        if ($query !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q')
                ->setParameter('q', '%' . addcslashes(mb_strtolower($query), '\\%_') . '%');
        }

        /** @var list<array{value: int, name: string, county: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $r): array => ['value' => $r['value'], 'label' => $r['name'] . ' · ' . $r['county']],
            $rows,
        );
    }

    /** @return Court[] */
    public function findActiveByCounty(string $county): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.county', 'co')
            ->leftJoin('c.coveredCities', 'cc')
            ->addSelect('cc')
            ->where('co.normalizedName = :county')
            ->andWhere('c.active = true')
            ->setParameter('county', LocalityNormalizer::normalize($county))
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Court[] */
    public function findActiveByTypeAndCounty(CourtType $type, string $county): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.county', 'co')
            ->leftJoin('c.coveredCities', 'cc')
            ->addSelect('cc')
            ->where('c.type = :type')
            ->andWhere('co.normalizedName = :county')
            ->andWhere('c.active = true')
            ->setParameter('type', $type)
            ->setParameter('county', LocalityNormalizer::normalize($county))
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
                ->select('DISTINCT co.name AS name')
                ->join('c.county', 'co')
                ->where('c.active = true')
                ->orderBy('co.name', 'ASC')
                ->getQuery()
                ->getScalarResult(),
            'name'
        );
    }
}
