<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\LegalCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLog> */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Recent audit entries for a case (entityType = LegalCase::class FQN, entityId = id string).
     * Matches the persistence convention used by CaseWizardController and AuditLogService
     * callers — see Pas 3.2 wizard submit + Pas 2.5.4 category indexing.
     *
     * @return AuditLog[]
     */
    public function findByCase(LegalCase $case, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :type')
            ->andWhere('a.entityId = :id')
            ->setParameter('type', LegalCase::class)
            ->setParameter('id', (string) $case->getId())
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
