<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilingAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FilingAttempt>
 */
class FilingAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FilingAttempt::class);
    }
}
