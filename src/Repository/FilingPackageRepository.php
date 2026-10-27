<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilingPackage;
use App\Entity\LegalCase;
use App\Enum\FilingPackageStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FilingPackage> */
class FilingPackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FilingPackage::class);
    }

    /** The highest version that has not been replaced. */
    public function findCurrentForCase(LegalCase $case): ?FilingPackage
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.legalCase = :case')
            ->andWhere('p.status != :superseded')
            ->setParameter('case', $case)
            ->setParameter('superseded', FilingPackageStatus::SUPERSEDED)
            ->orderBy('p.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function nextVersion(LegalCase $case): int
    {
        $max = $this->createQueryBuilder('p')
            ->select('MAX(p.version)')
            ->andWhere('p.legalCase = :case')
            ->setParameter('case', $case)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
