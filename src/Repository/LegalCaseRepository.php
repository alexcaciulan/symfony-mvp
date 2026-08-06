<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CaseStatusHistory;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\StampDutyStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
        return $this->activeByUserQueryBuilder($user)->getQuery()->getResult();
    }

    /**
     * The query behind {@see self::findActiveByUser()}, unexecuted, for the form
     * types that need the user's own cases as choices. Handing over the builder is
     * what keeps the scoping of a case selector identical to the scoping of every
     * other list of active cases, instead of restated per form.
     */
    public function activeByUserQueryBuilder(User $user): QueryBuilder
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
            ->orderBy('lc.updatedAt', 'DESC');
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
     *
     * Restricted to RON-denominated cases: the KPI is labelled RON, and a case
     * whose positions all still await a manual exchange rate keeps its amount in
     * the original currency (see ClaimTotalsService::recalculate). Summing those
     * into a RON total would add unlike currencies under one label.
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
            ->andWhere('lc.currency = :ron')
            ->setParameter('user', $user)
            ->setParameter('terminal', $terminal)
            ->setParameter('ron', 'RON')
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

    /**
     * Cases whose summons was generated while the date the debtor received it was
     * never recorded. The 15-day term runs from that receipt (CPC art. 1015 para. 1),
     * so until the date exists the RASPUNS_SOMATIE deadline stays an estimate seeded
     * from the generation date.
     *
     * Restricted to the statuses before filing: once the payment order request is
     * out, recording the receipt no longer changes what the lawyer has to do next,
     * and the row would sit in the blockage list forever.
     *
     * @return LegalCase[] oldest summons first, that being the one closest to filing
     */
    public function findAwaitingSummonsCommunicationDate(User $user): array
    {
        return $this->blockedCasesQueryBuilder($user)
            ->andWhere('lc.status IN (:statuses)')
            ->andWhere('lc.paymentNoticeDate IS NOT NULL')
            ->andWhere('lc.paymentNoticeCommunicationDate IS NULL')
            ->setParameter('statuses', [CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA])
            ->orderBy('lc.paymentNoticeDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cases where the order was issued and the date it was communicated is still
     * missing. That date is the only thing the 10-day annulment term runs from
     * (CPC art. 1024 para. 1), so without it the deadline is never created.
     *
     * Only ORDONANTA_EMISA qualifies: in IN_ANULARE the annulment request has already
     * been filed, and past DEFINITIVA the window has closed, so in both the prompt
     * would ask for a date that no longer unblocks anything.
     *
     * @return LegalCase[] oldest first
     */
    public function findAwaitingRulingCommunicationDate(User $user): array
    {
        return $this->blockedCasesQueryBuilder($user)
            ->andWhere('lc.status = :status')
            ->andWhere('lc.rulingCommunicationDate IS NULL')
            ->setParameter('status', CaseStatus::ORDONANTA_EMISA)
            ->orderBy('lc.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cases filed without proof of stamping and without the court notice that starts
     * the 10-day term to stamp (OUG 80/2013 art. 33 para. 2). The notice date is not
     * stored on the case, only in the audit log, so the evidence that it was recorded
     * is the existence of the TIMBRARE deadline itself, the same reading
     * {@see \App\Service\Deadline\DeadlineCertaintyResolver} relies on.
     *
     * Cases with the duty already paid are excluded on purpose: the court issues no
     * regularization notice for them, so there is nothing missing.
     *
     * @return LegalCase[] oldest first
     */
    public function findAwaitingStampDutyCourtNotice(User $user): array
    {
        $withStampDutyDeadline = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(LegalDeadline::class, 'sd')
            ->where('sd.legalCase = lc')
            ->andWhere('sd.type = :stampDutyType');

        $qb = $this->blockedCasesQueryBuilder($user);

        return $qb
            ->andWhere('lc.status IN (:statuses)')
            ->andWhere('lc.stampDutyStatus IN (:stampDutyStatuses)')
            ->andWhere($qb->expr()->not($qb->expr()->exists($withStampDutyDeadline->getDQL())))
            ->setParameter('statuses', [CaseStatus::CERERE_DEPUSA, CaseStatus::DOSAR_INREGISTRAT, CaseStatus::TERMEN_FIXAT])
            ->setParameter('stampDutyStatuses', [StampDutyStatus::NEACHITATA, StampDutyStatus::AMANATA_REGULARIZARE])
            ->setParameter('stampDutyType', DeadlineType::TIMBRARE)
            ->orderBy('lc.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cases that are final while neither the date the order was communicated nor the
     * date it was pronounced is recorded. The three years of CPC art. 705 para. 1 run
     * from the day the order became final (para. 2), and with both dates missing there
     * is no anchor that is not later than the real one, so
     * {@see \App\EventSubscriber\DeadlineCreationSubscriber} creates no term at all
     * and the case shows up here instead.
     *
     * EXECUTARE is deliberately out of the status set: the request filed with the
     * bailiff interrupts that term (CPC art. 708 para. 1 pt. 2) and closes it, so
     * asking for an anchor there would be asking for the starting date of a term the
     * application has just closed. A case that entered enforcement without that filing
     * date recorded keeps its term open instead, which is where it belongs, and the
     * agenda carries it as an ordinary deadline rather than as a blockage.
     *
     * Cases that went through an annulment request are out too: their anchor is a
     * different date and they are listed by
     * {@see self::findAwaitingAnnulmentRulingCommunicationDate()}. Splitting them keeps
     * one case to one blockage.
     *
     * Cases that already carry the deadline are excluded: it may have been created
     * from a date that was later removed, and re-listing them would ask for something
     * the agenda already tracks.
     *
     * @return LegalCase[] oldest first
     */
    public function findAwaitingExecutionPrescriptionAnchor(User $user): array
    {
        $withExecutionPrescription = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(LegalDeadline::class, 'ep')
            ->where('ep.legalCase = lc')
            ->andWhere('ep.type = :executionPrescriptionType');

        $qb = $this->blockedCasesQueryBuilder($user);

        return $qb
            ->andWhere('lc.status = :status')
            ->andWhere('lc.rulingCommunicationDate IS NULL')
            ->andWhere('lc.finalRulingDate IS NULL')
            ->andWhere($qb->expr()->not($qb->expr()->exists($withExecutionPrescription->getDQL())))
            ->andWhere($qb->expr()->not($qb->expr()->exists($this->annulmentHistoryDql())))
            ->setParameter('status', CaseStatus::DEFINITIVA)
            ->setParameter('executionPrescriptionType', DeadlineType::PRESCRIPTIE_EXECUTARE)
            ->setParameter('annulmentStatus', CaseStatus::IN_ANULARE->value)
            ->orderBy('lc.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cases that went through an annulment request, are now final, and do not carry the
     * date the ruling on that request was communicated. That ruling is what made the
     * order final (CPC art. 1024 para. 8), so the three years of CPC art. 705 para. 1
     * run from its communication (para. 2); anchoring on the first ruling would give a
     * term that expires earlier than the real one.
     *
     * The passage through IN_ANULARE is read from the status history because the case
     * has already left that place by the time the date is needed.
     *
     * Disjoint from {@see self::findAwaitingExecutionPrescriptionAnchor()}, which
     * excludes exactly the cases this one selects, so a case still contributes at most
     * one blockage. Cases that already carry the enforcement-limitation term are
     * excluded for the same reason as there.
     *
     * @return LegalCase[] oldest first
     */
    public function findAwaitingAnnulmentRulingCommunicationDate(User $user): array
    {
        $withExecutionPrescription = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(LegalDeadline::class, 'ep')
            ->where('ep.legalCase = lc')
            ->andWhere('ep.type = :executionPrescriptionType');

        $qb = $this->blockedCasesQueryBuilder($user);

        return $qb
            ->andWhere('lc.status = :status')
            ->andWhere('lc.annulmentRulingCommunicationDate IS NULL')
            ->andWhere($qb->expr()->exists($this->annulmentHistoryDql()))
            ->andWhere($qb->expr()->not($qb->expr()->exists($withExecutionPrescription->getDQL())))
            ->setParameter('status', CaseStatus::DEFINITIVA)
            ->setParameter('executionPrescriptionType', DeadlineType::PRESCRIPTIE_EXECUTARE)
            ->setParameter('annulmentStatus', CaseStatus::IN_ANULARE->value)
            ->orderBy('lc.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cases whose court file number is known while the stamp duty is still recorded as
     * unpaid, across all users, for the daily alerting job. The duty is paid in advance
     * (OUG 80/2013 art. 33 para. 1), so the file number appearing on the portal is the
     * moment it is due; waiting for a regularization notice is waiting for the court to
     * ask.
     *
     * Cases already carrying a TIMBRARE deadline are excluded: there the term is
     * running and the ordinary deadline alerts cover it, so this would be a second
     * voice on the same fact. Cases with the duty deferred pending regularization are
     * excluded too; they are the subject of
     * {@see self::findAwaitingRegularizationNoticeDate()}, and the two sets stay
     * disjoint on `stampDutyStatus` so one case never produces two messages.
     *
     * @return LegalCase[] oldest first
     */
    public function findStampDutyDueAfterCaseNumber(): array
    {
        return $this->stampDutyAlertQueryBuilder()
            ->andWhere('lc.courtCaseNumber IS NOT NULL')
            ->andWhere('lc.stampDutyStatus = :stampDutyStatus')
            ->setParameter('stampDutyStatus', StampDutyStatus::NEACHITATA)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cases whose stamp duty was deferred pending a regularization notice, with no
     * TIMBRARE deadline, across all users. The ten days of OUG 80/2013 art. 33 para. 2
     * run from the communication of that notice, a date the application never learns on
     * its own, so as long as it is missing the term exists nowhere.
     *
     * @return LegalCase[] oldest first
     */
    public function findAwaitingRegularizationNoticeDate(): array
    {
        return $this->stampDutyAlertQueryBuilder()
            ->andWhere('lc.stampDutyStatus = :stampDutyStatus')
            ->setParameter('stampDutyStatus', StampDutyStatus::AMANATA_REGULARIZARE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Shared skeleton of the two stamp-duty alert queries: a live case with the claim
     * already at the court and no stamping term running on it. Not scoped per user, the
     * consumer being a cron job; the recipient is read from each case.
     */
    private function stampDutyAlertQueryBuilder(): QueryBuilder
    {
        $withStampDutyDeadline = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(LegalDeadline::class, 'sd')
            ->where('sd.legalCase = lc')
            ->andWhere('sd.type = :stampDutyType');

        $qb = $this->createQueryBuilder('lc');

        return $qb
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status IN (:statuses)')
            ->andWhere($qb->expr()->not($qb->expr()->exists($withStampDutyDeadline->getDQL())))
            ->setParameter('statuses', [CaseStatus::CERERE_DEPUSA, CaseStatus::DOSAR_INREGISTRAT, CaseStatus::TERMEN_FIXAT])
            ->setParameter('stampDutyType', DeadlineType::TIMBRARE)
            ->orderBy('lc.updatedAt', 'ASC');
    }

    /** Subquery DQL: the case entered IN_ANULARE at some point. Binds `annulmentStatus`. */
    private function annulmentHistoryDql(): string
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(CaseStatusHistory::class, 'csh')
            ->where('csh.legalCase = lc')
            ->andWhere('csh.newStatus = :annulmentStatus')
            ->getDQL();
    }

    /**
     * Base filter shared by the blockage queries: the case belongs to the user and is
     * still live. Debtors and court are fetch joined because every blockage row names
     * the opposing party and the court it is filed at.
     */
    private function blockedCasesQueryBuilder(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('lc')
            ->leftJoin('lc.debtors', 'db')->addSelect('db')
            ->leftJoin('lc.court', 'ct')->addSelect('ct')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->setParameter('user', $user);
    }
}
