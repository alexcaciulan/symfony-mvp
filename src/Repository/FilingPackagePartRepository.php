<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilingPackagePart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FilingPackagePart> */
class FilingPackagePartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FilingPackagePart::class);
    }
}
