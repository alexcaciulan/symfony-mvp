<?php

namespace App\Repository;

use App\Entity\City;
use App\Entity\County;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<City> */
class CityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, City::class);
    }

    public function findOneByCountyAndNormalizedName(County $county, string $normalizedName): ?City
    {
        return $this->findOneBy(['county' => $county, 'normalizedName' => $normalizedName]);
    }

    /**
     * Resolve a city by county name and city name, both already normalized.
     * Joins County so callers do not need to load it first.
     */
    public function findOneByCountyNameAndNormalizedName(string $countyNormalizedName, string $cityNormalizedName): ?City
    {
        return $this->createQueryBuilder('c')
            ->join('c.county', 'co')
            ->where('co.normalizedName = :county')
            ->andWhere('c.normalizedName = :city')
            ->setParameter('county', $countyNormalizedName)
            ->setParameter('city', $cityNormalizedName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
