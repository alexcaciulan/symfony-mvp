<?php

namespace App\Repository;

use App\Entity\County;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<County> */
class CountyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, County::class);
    }

    public function findOneByNormalizedName(string $normalizedName): ?County
    {
        return $this->findOneBy(['normalizedName' => $normalizedName]);
    }

    /** @return County[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
