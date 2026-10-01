<?php

namespace App\Repository;

use App\Entity\LegalCaseDebtor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LegalCaseDebtor> */
class LegalCaseDebtorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalCaseDebtor::class);
    }
}
