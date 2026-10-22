<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilingEmailPart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FilingEmailPart>
 */
class FilingEmailPartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FilingEmailPart::class);
    }
}
