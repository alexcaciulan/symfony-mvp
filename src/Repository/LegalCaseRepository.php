<?php

namespace App\Repository;

use App\Entity\LegalCase;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LegalCase> */
class LegalCaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalCase::class);
    }

    /** @return LegalCase[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('lc.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.status = :status')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find cases eligible for portal.just.ro monitoring.
     * Cases must have a caseNumber, be in an active status, and have a court with portalCode.
     *
     * @param string[] $statuses
     *
     * @return LegalCase[]
     */
    public function findMonitorableCases(array $statuses): array
    {
        return $this->createQueryBuilder('lc')
            ->join('lc.court', 'c')
            ->where('lc.status IN (:statuses)')
            ->andWhere('lc.caseNumber IS NOT NULL')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('c.portalCode IS NOT NULL')
            ->setParameter('statuses', $statuses)
            ->orderBy('lc.lastPortalCheckAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
