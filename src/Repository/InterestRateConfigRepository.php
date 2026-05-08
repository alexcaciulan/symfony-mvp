<?php

namespace App\Repository;

use App\Entity\InterestRateConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InterestRateConfig> */
class InterestRateConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InterestRateConfig::class);
    }

    public function findRateValidAt(\DateTimeInterface $date): ?InterestRateConfig
    {
        return $this->createQueryBuilder('r')
            ->where('r.validFrom <= :date')
            ->setParameter('date', $date)
            ->orderBy('r.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return InterestRateConfig[] ordered ASC by validFrom */
    public function findAllValidUpTo(\DateTimeInterface $date): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.validFrom <= :date')
            ->setParameter('date', $date)
            ->orderBy('r.validFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
