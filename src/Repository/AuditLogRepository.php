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

    /** Whether the case's trail holds at least one entry with this action. */
    public function hasActionForCase(LegalCase $case, string $action): bool
    {
        return (bool) $this->createQueryBuilder('a')
            ->select('1')
            ->andWhere('a.entityType = :type')
            ->andWhere('a.entityId = :id')
            ->andWhere('a.action = :action')
            ->setParameter('type', LegalCase::class)
            ->setParameter('id', (string) $case->getId())
            ->setParameter('action', $action)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
     * Changes to the case's debtor company (or, with the creditor action, its
     * creditor) recorded after the given moment, oldest first.
     *
     * @return list<AuditLog>
     */
    public function findDebtorChangesSince(LegalCase $case, \DateTimeInterface $since, string $action = 'debtor_identity_changed'): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :type')
            ->andWhere('a.entityId = :id')
            ->andWhere('a.action = :action')
            ->andWhere('a.createdAt > :since')
            ->setParameter('type', LegalCase::class)
            ->setParameter('id', (string) $case->getId())
            ->setParameter('action', $action)
            ->setParameter('since', \DateTimeImmutable::createFromInterface($since))
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Replaces the given strings in the stored payloads of one entity's audit rows,
     * so an erasure request also reaches what the trail recorded about it.
     *
     * @param array<string, string> $replace search => replacement
     */
    public function redactEntityPayloads(string $entityType, string $entityId, array $replace): int
    {
        /** @var list<AuditLog> $rows */
        $rows = $this->findBy(['entityType' => $entityType, 'entityId' => $entityId]);
        $redact = static function (mixed $value) use (&$redact, $replace): mixed {
            if (is_string($value)) {
                return str_ireplace(array_keys($replace), array_values($replace), $value);
            }
            if (is_array($value)) {
                $out = [];
                foreach ($value as $key => $item) {
                    $out[is_string($key) ? $redact($key) : $key] = $redact($item);
                }

                return $out;
            }

            return $value;
        };

        foreach ($rows as $row) {
            $row->setOldData($row->getOldData() !== null ? $redact($row->getOldData()) : null);
            $row->setNewData($row->getNewData() !== null ? $redact($row->getNewData()) : null);
        }

        return count($rows);
    }
}
