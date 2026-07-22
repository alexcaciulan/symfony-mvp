<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ClaimItem> */
class ClaimItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClaimItem::class);
    }

    public function findOneByDedupKey(LegalCase $case, string $dedupKey): ?ClaimItem
    {
        return $this->findOneBy(['legalCase' => $case, 'dedupKey' => $dedupKey]);
    }
}
