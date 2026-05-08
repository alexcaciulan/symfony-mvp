<?php

namespace App\Repository;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
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

    /**
     * Find non-terminal cases for a user, ordered by recency.
     *
     * @return LegalCase[]
     */
    public function findActiveByUser(User $user): array
    {
        $terminal = array_filter(
            CaseStatus::cases(),
            static fn (CaseStatus $s): bool => $s->isTerminal()
        );

        return $this->createQueryBuilder('lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status NOT IN (:terminal)')
            ->setParameter('user', $user)
            ->setParameter('terminal', $terminal)
            ->orderBy('lc.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Group active cases for a user by status, with deterministic priority ordering
     * (urgent statuses first). Used by dashboard.
     *
     * @return array<string, LegalCase[]> keyed by status value
     */
    public function findByStatusGroupedByUrgency(User $user): array
    {
        $cases = $this->createQueryBuilder('lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('lc.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $priority = [
            CaseStatus::TERMEN_FIXAT->value,
            CaseStatus::CONTESTATA->value,
            CaseStatus::ORDONANTA_EMISA->value,
            CaseStatus::DOSAR_INREGISTRAT->value,
            CaseStatus::CERERE_DEPUSA->value,
            CaseStatus::SOMATIE_TRIMISA->value,
            CaseStatus::AMIABIL->value,
            CaseStatus::DEFINITIVA->value,
            CaseStatus::EXECUTARE->value,
            CaseStatus::INCHIS_SUCCES->value,
            CaseStatus::INCHIS_PARTIAL_INSOLVABIL->value,
            CaseStatus::RESPINSA->value,
        ];

        $grouped = array_fill_keys($priority, []);
        foreach ($cases as $case) {
            $grouped[$case->getStatus()->value][] = $case;
        }

        return array_filter($grouped, static fn (array $group): bool => $group !== []);
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
