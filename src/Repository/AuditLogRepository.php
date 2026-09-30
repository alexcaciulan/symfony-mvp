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

    /**
     * When the case's first somatie was generated; null for a case whose
     * somatie predates this record.
     */
    public function findFirstSummonsGeneratedAt(LegalCase $case): ?\DateTimeImmutable
    {
        $entry = $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :type')
            ->andWhere('a.entityId = :id')
            ->andWhere('a.action = :action')
            ->setParameter('type', LegalCase::class)
            ->setParameter('id', (string) $case->getId())
            ->setParameter('action', 'summons_generated')
            ->orderBy('a.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry instanceof AuditLog ? \DateTimeImmutable::createFromInterface($entry->getCreatedAt()) : null;
    }

    /**
     * Changes to the case's debtor company recorded after the given moment,
     * oldest first.
     *
     * @return list<AuditLog>
     */
    public function findDebtorChangesSince(LegalCase $case, \DateTimeInterface $since): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :type')
            ->andWhere('a.entityId = :id')
            ->andWhere('a.action = :action')
            ->andWhere('a.createdAt > :since')
            ->setParameter('type', LegalCase::class)
            ->setParameter('id', (string) $case->getId())
            ->setParameter('action', 'debtor_identity_changed')
            ->setParameter('since', \DateTimeImmutable::createFromInterface($since))
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
