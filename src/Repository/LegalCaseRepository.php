<?php

declare(strict_types=1);

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

    /**
     * Eager-load for the case overview page (Pas 4.0.2+). Explicit `sh.createdAt ASC`
     * because OrderBy on the OneToMany applies only to lazy-loads, not joined hydration.
     */
    public function findWithOverviewRelations(int $id): ?LegalCase
    {
        return $this->createQueryBuilder('lc')
            ->leftJoin('lc.creditor', 'cr')->addSelect('cr')
            ->leftJoin('lc.debtors', 'db')->addSelect('db')
            ->leftJoin('lc.court', 'ct')->addSelect('ct')
            ->leftJoin('lc.statusHistory', 'sh')->addSelect('sh')
            ->andWhere('lc.id = :id')
            ->setParameter('id', $id)
            ->addOrderBy('sh.createdAt', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
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
     * Most recent cases for a user, capped at $limit.
     *
     * @return LegalCase[]
     */
    public function findRecentByUser(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilder('lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('lc.createdAt', 'DESC')
            ->setMaxResults($limit)
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
            CaseStatus::IN_ANULARE->value,
            CaseStatus::ORDONANTA_EMISA->value,
            CaseStatus::DOSAR_INREGISTRAT->value,
            CaseStatus::CERERE_DEPUSA->value,
            CaseStatus::SOMATIE_TRIMISA->value,
            CaseStatus::AMIABIL->value,
            CaseStatus::DEFINITIVA->value,
            CaseStatus::EXECUTARE->value,
            CaseStatus::INCHIS_SUCCES->value,
            CaseStatus::INCHIS_FARA_RECUPERARE->value,
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

    public function countActive(): int
    {
        $terminal = array_filter(
            CaseStatus::cases(),
            static fn (CaseStatus $s): bool => $s->isTerminal()
        );

        return (int) $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.deletedAt IS NULL')
            ->andWhere('lc.status NOT IN (:terminal)')
            ->setParameter('terminal', $terminal)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count payment orders issued (finalRulingDate set at the `emite_ordonanta`
     * transition) since the first day of the current month. This is the ruling
     * issuance date (CPC art. 1021), not the date the order becomes final.
     * Used by the admin dashboard KPI.
     */
    public function countRulingsIssuedThisMonth(): int
    {
        $start = new \DateTimeImmutable('first day of this month 00:00');

        return (int) $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.deletedAt IS NULL')
            ->andWhere('lc.finalRulingDate >= :start')
            ->setParameter('start', $start)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveByUser(User $user): int
    {
        $terminal = array_filter(
            CaseStatus::cases(),
            static fn (CaseStatus $s): bool => $s->isTerminal()
        );

        return (int) $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status NOT IN (:terminal)')
            ->setParameter('user', $user)
            ->setParameter('terminal', $terminal)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sum of principal claim amounts on active (non-terminal) cases for a user.
     * Used by dashboard KPI "În recuperare".
     */
    public function sumActiveAmountByUser(User $user): float
    {
        $terminal = array_filter(
            CaseStatus::cases(),
            static fn (CaseStatus $s): bool => $s->isTerminal()
        );

        $result = $this->createQueryBuilder('lc')
            ->select('COALESCE(SUM(lc.amount), 0) AS total')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status NOT IN (:terminal)')
            ->setParameter('user', $user)
            ->setParameter('terminal', $terminal)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $result;
    }

    /**
     * Find cases eligible for daily portal.just.ro monitoring (Pas 6.1).
     * Eligibilitate (spec ANALIZA-FLUXURI secțiunea 10): monitorizare activată
     * explicit (`portalMonitoringActive`), număr de dosar instanță completat
     * (`courtCaseNumber`), status în setul activ pe portal
     * ({@see CaseStatus::isActiveOnPortal()}: DOSAR_INREGISTRAT, TERMEN_FIXAT,
     * ORDONANTA_EMISA, IN_ANULARE), instanță cu cod portal, dosar nesoftșters.
     * Ordonat după `lastPortalCheckAt ASC` (cele mai vechi verificate primele).
     *
     * @return LegalCase[]
     */
    public function findActiveForMonitoring(): array
    {
        $activeStatuses = array_values(array_filter(
            CaseStatus::cases(),
            static fn (CaseStatus $s): bool => $s->isActiveOnPortal(),
        ));

        return $this->createQueryBuilder('lc')
            ->join('lc.court', 'c')
            ->where('lc.portalMonitoringActive = true')
            ->andWhere('lc.courtCaseNumber IS NOT NULL')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status IN (:activeStatuses)')
            ->andWhere('c.portalCode IS NOT NULL')
            ->setParameter('activeStatuses', $activeStatuses)
            ->orderBy('lc.lastPortalCheckAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Non-soft-deleted cases with a given status. Used by the auto-finalization
     * cron (CaseAutoFinalizer) to iterate cases in ORDONANTA_EMISA.
     *
     * @return LegalCase[]
     */
    public function findByStatus(CaseStatus $status): array
    {
        return $this->createQueryBuilder('lc')
            ->where('lc.status = :status')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('status', $status)
            ->orderBy('lc.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
