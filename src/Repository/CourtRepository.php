<?php

namespace App\Repository;

use App\Entity\Court;
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
