<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Service\Deadline\DeadlineAgendaFilter;
use App\Service\Deadline\DeadlineConsequenceResolver;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/** @extends ServiceEntityRepository<LegalDeadline> */
class LegalDeadlineRepository extends ServiceEntityRepository
{
    private const PRESCRIPTION_TYPES = [
        DeadlineType::PRESCRIPTIE,
        DeadlineType::PRESCRIPTIE_EXECUTARE,
    ];

    public function __construct(
        ManagerRegistry $registry,
        private readonly DeadlineConsequenceResolver $consequenceResolver,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, LegalDeadline::class);
    }

    /** @return LegalDeadline[] */
    public function findByCase(LegalCase $case): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.legalCase = :case')
            ->setParameter('case', $case)
            ->orderBy('d.deadlineDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All incomplete deadlines (global, not scoped per user) for the alert cron
     * (DeadlineAlertService). Excludes deadlines on soft-deleted cases. Ordered
     * chronologically for deterministic output.
     *
     * @return LegalDeadline[]
     */
    public function findIncomplete(): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.legalCase', 'lc')
            ->andWhere('d.completed = false')
            ->andWhere('lc.deletedAt IS NULL')
            ->orderBy('d.deadlineDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Idempotency lookup pentru DeadlineCreationSubscriber: termenele automate sunt unice per (dosar, tip). */
    public function findOneByCaseAndType(LegalCase $case, DeadlineType $type): ?LegalDeadline
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.legalCase = :case')
            ->andWhere('d.type = :type')
            ->setParameter('case', $case)
            ->setParameter('type', $type)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Idempotency lookup pentru termenele detectate din portal (Pas 6.1
     * MonitoringEventApplier): un termen JUDECATA poate exista de mai multe ori
     * pe un dosar (ședințe multiple), deci dedup-ul se face pe (dosar, tip, dată),
     * NU doar pe (dosar, tip) ca {@see self::findOneByCaseAndType()}.
     */
    public function findOneByCaseTypeAndDate(LegalCase $case, DeadlineType $type, \DateTimeInterface $date): ?LegalDeadline
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.legalCase = :case')
            ->andWhere('d.type = :type')
            ->andWhere('d.deadlineDate = :date')
            ->setParameter('case', $case)
            ->setParameter('type', $type)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Open deadlines of the user falling between today and today plus $days, both
     * bounds included, for the dashboard list. Same window as
     * {@see self::countUpcomingByUser()} by construction: the list is shown under a
     * subtitle carrying that count, so the two must select the same rows.
     *
     * @return LegalDeadline[] ascending by deadline date
     */
    public function findUpcomingByUser(User $user, int $days = 30, ?int $limit = null): array
    {
        $qb = $this->upcomingQueryBuilder($user, $days)
            ->orderBy('d.deadlineDate', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count of the open deadlines falling inside the next $days, for the dashboard KPI
     * "Termene urgente". Same definition as {@see self::findUpcomingByUser()}.
     */
    public function countUpcomingByUser(User $user, int $days = 7): int
    {
        return (int) $this->upcomingQueryBuilder($user, $days)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Shared body of the two dashboard "upcoming" queries.
     *
     * Bounded on BOTH sides. Without the lower bound the KPI counted every deadline
     * that had ever passed under a caption promising the next few days, which turned
     * arrears already shown by their own counter into a second, larger number nobody
     * could act on. Arrears are {@see self::countOverdueByUser()}; this is what is
     * still ahead.
     *
     * Terminal cases are excluded for the same reason the agenda excludes them: a
     * closed case has nothing left to do, and a deadline still open on it is a leftover
     * record, not work. This aligns the dashboard with the agenda the KPI links to.
     *
     * Compared on the calendar DATE: a deadline dated today belongs to the window for
     * its whole last day (CPC art. 182 para. 1).
     */
    private function upcomingQueryBuilder(User $user, int $days): QueryBuilder
    {
        $terminal = array_filter(
            CaseStatus::cases(),
            static fn (CaseStatus $s): bool => $s->isTerminal(),
        );

        $today = $this->today();

        return $this->createQueryBuilder('d')
            ->join('d.legalCase', 'lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status NOT IN (:terminal)')
            ->andWhere('d.completed = false')
            ->andWhere('d.deadlineDate >= :today')
            ->andWhere('d.deadlineDate <= :cutoff')
            ->setParameter('user', $user)
            ->setParameter('terminal', $terminal)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->setParameter('cutoff', $today->modify("+{$days} days"), Types::DATE_IMMUTABLE);
    }

    /**
     * The number of arrears of a user: past-due, still open, on a live non-terminal
     * case, minus the terms of the debtor.
     *
     * Deliberately a delegation rather than a query of its own. The dashboard KPI, the
     * sidebar badge and the "Restante" pill of the agenda are the same number shown in
     * three places, and the dashboard card links straight to the agenda, so a second
     * SQL statement restating the rule would be free to drift from it. The definition
     * therefore lives once, in {@see self::countAgendaBuckets()}.
     */
    public function countOverdueByUser(User $user): int
    {
        return $this->countAgendaBuckets($user)['overdue'];
    }

    /**
     * Open deadlines of the user falling inside [$from, $to] (both bounds included),
     * for the global agenda page. Fetch joins the case, its court and its debtors so
     * rendering a row never triggers an extra query per deadline.
     *
     * The comparison is done on the calendar DATE, not on a datetime: a deadline is
     * still "inside the window" for its whole last day (CPC art. 182 para. 1).
     *
     * @return LegalDeadline[] ascending by deadline date
     */
    public function findAgendaForUser(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, ?DeadlineAgendaFilter $filter = null): array
    {
        return $this->agendaQueryBuilder($user, $filter)
            ->addSelect('lc', 'court', 'debtors')
            ->leftJoin('lc.court', 'court')
            ->leftJoin('lc.debtors', 'debtors')
            ->andWhere('d.deadlineDate >= :from')
            ->andWhere('d.deadlineDate <= :to')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->orderBy('d.deadlineDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open deadlines of the user whose date has already passed and whose expiry is a
     * failure of the lawyer. Strictly before today's DATE: a deadline dated today is
     * NOT overdue, because the term runs until the end of that day (CPC art. 182
     * para. 1). This is deliberately not {@see LegalDeadline::isOverdue()}, which
     * compares a midnight date against the current datetime and therefore reports
     * today's deadlines as missed.
     *
     * Terms of the debtor are excluded and listed by
     * {@see self::findExpiredDebtorTermsForUser()} instead: their expiry is what
     * opens the filing of the request, not a delay of the lawyer, so counting them
     * as arrears would inflate the one number the morning triage is read from.
     *
     * @return LegalDeadline[] ascending by deadline date
     */
    public function findOverdueForUser(User $user, ?DeadlineAgendaFilter $filter = null): array
    {
        return $this->overdueQueryBuilder($user, $filter)
            ->andWhere('d.type NOT IN (:unsanctionedTypes)')
            ->setParameter('unsanctionedTypes', $this->consequenceResolver->unsanctionedTypes())
            ->getQuery()
            ->getResult();
    }

    /**
     * The counterpart of {@see self::findOverdueForUser()}: past-due terms belonging
     * to the debtor, whose expiry is favourable to the client because it unblocks
     * filing the payment order (CPC art. 1015-1016).
     *
     * @return LegalDeadline[] ascending by deadline date
     */
    public function findExpiredDebtorTermsForUser(User $user, ?DeadlineAgendaFilter $filter = null): array
    {
        return $this->overdueQueryBuilder($user, $filter)
            ->andWhere('d.type IN (:unsanctionedTypes)')
            ->setParameter('unsanctionedTypes', $this->consequenceResolver->unsanctionedTypes())
            ->getQuery()
            ->getResult();
    }

    /**
     * The agenda risk bar in a single aggregate SELECT, so the page does not fire
     * one COUNT per pill. All three buckets share the same base filter as
     * {@see self::findAgendaForUser()}: open deadline, live case, non-terminal status.
     *
     * - overdue: date strictly before today, minus the terms of the debtor, for the
     *   reason spelled out on {@see self::findOverdueForUser()}; the pill and the
     *   sidebar badge must count exactly what the red section lists
     * - today: date exactly today
     * - fatal30: date between today and today+30 days AND a type whose miss is
     *   irreversible, read from {@see DeadlineConsequenceResolver::irreversibleTypes()}
     *   so the bar follows the legal mapping without restating it here
     *
     * Deliberately not filtered: the pills describe the whole open agenda even while
     * one of them is pressed. A counter that shrank to the selection it produced
     * would stop being a measure of the risk and start being a measure of the click.
     *
     * @return array{overdue: int, today: int, fatal30: int}
     */
    public function countAgendaBuckets(User $user): array
    {
        $today = $this->today();

        $row = $this->agendaQueryBuilder($user)
            ->select(
                'SUM(CASE WHEN d.deadlineDate < :today AND d.type NOT IN (:unsanctionedTypes) THEN 1 ELSE 0 END) AS overdue',
                'SUM(CASE WHEN d.deadlineDate = :today THEN 1 ELSE 0 END) AS today',
                'SUM(CASE WHEN d.deadlineDate >= :today AND d.deadlineDate <= :horizon AND d.type IN (:fatalTypes) THEN 1 ELSE 0 END) AS fatal30',
            )
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->setParameter('horizon', $today->modify('+30 days'), Types::DATE_IMMUTABLE)
            ->setParameter('fatalTypes', $this->consequenceResolver->irreversibleTypes())
            ->setParameter('unsanctionedTypes', $this->consequenceResolver->unsanctionedTypes())
            ->getQuery()
            ->getSingleResult();

        return [
            'overdue' => (int) $row['overdue'],
            'today' => (int) $row['today'],
            'fatal30' => (int) $row['fatal30'],
        ];
    }

    /**
     * Prescription deadlines further out than the agenda horizon (today+30 days).
     * They never show up in the agenda body, yet missing one extinguishes the right
     * itself (NCC art. 2517, CPC art. 705 para. 1), so the page keeps them in view.
     *
     * Only the to-one associations are fetch joined: a collection join combined with
     * the row limit would make the LIMIT apply to the multiplied rows. The debtors,
     * which the rail names on every row, are loaded right after in one extra query
     * by {@see self::warmDebtors()}.
     *
     * @return LegalDeadline[] ascending by deadline date
     */
    public function findLongHorizonPrescriptionsForUser(User $user, int $limit = 5, ?DeadlineAgendaFilter $filter = null): array
    {
        $deadlines = $this->agendaQueryBuilder($user, $filter)
            ->addSelect('lc', 'court')
            ->leftJoin('lc.court', 'court')
            ->andWhere('d.type IN (:prescriptionTypes)')
            ->andWhere('d.deadlineDate > :horizon')
            ->setParameter('prescriptionTypes', self::PRESCRIPTION_TYPES)
            ->setParameter('horizon', $this->today()->modify('+30 days'), Types::DATE_IMMUTABLE)
            ->orderBy('d.deadlineDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $this->warmDebtors($deadlines);

        return $deadlines;
    }

    /**
     * Initialises the debtor collections of the given deadlines' cases in a single
     * query. The association is lazy, so a template naming the debtor would otherwise
     * fire one SELECT per row. The result is discarded on purpose: what the caller
     * needs is the collections hydrated in the identity map, and going through a
     * separate query is what keeps the row limit of the first one intact.
     *
     * @param LegalDeadline[] $deadlines
     */
    private function warmDebtors(array $deadlines): void
    {
        $caseIds = array_values(array_unique(array_map(
            static fn (LegalDeadline $deadline): ?int => $deadline->getLegalCase()->getId(),
            $deadlines,
        )));

        if ($caseIds === []) {
            return;
        }

        $this->getEntityManager()
            ->createQuery(sprintf('SELECT c, debtors FROM %s c LEFT JOIN c.debtors debtors WHERE c.id IN (:ids)', LegalCase::class))
            ->setParameter('ids', $caseIds)
            ->getResult();
    }

    /**
     * Shared body of the two past-due queries: same window, same fetch joins, same
     * order, so the red section and the unblocked section can never disagree on
     * anything but the type filter each of them adds.
     */
    private function overdueQueryBuilder(User $user, ?DeadlineAgendaFilter $filter = null): QueryBuilder
    {
        return $this->agendaQueryBuilder($user, $filter)
            ->addSelect('lc', 'court', 'debtors')
            ->leftJoin('lc.court', 'court')
            ->leftJoin('lc.debtors', 'debtors')
            ->andWhere('d.deadlineDate < :today')
            ->setParameter('today', $this->today(), Types::DATE_IMMUTABLE)
            ->orderBy('d.deadlineDate', 'ASC');
    }

    /**
     * Base filter shared by every agenda query: the deadline is still open, it
     * belongs to the user, the case is not soft-deleted and the case is not in a
     * terminal status (a closed case must never inflate the agenda).
     *
     * Passing no filter leaves the query exactly as the unfiltered page and the
     * counters need it, which is what the aggregate SELECT relies on.
     */
    private function agendaQueryBuilder(User $user, ?DeadlineAgendaFilter $filter = null): QueryBuilder
    {
        $terminal = array_filter(
            CaseStatus::cases(),
            static fn (CaseStatus $s): bool => $s->isTerminal(),
        );

        $qb = $this->createQueryBuilder('d')
            ->join('d.legalCase', 'lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status NOT IN (:terminal)')
            ->setParameter('user', $user)
            ->setParameter('terminal', $terminal);

        // The agenda is what is still open. Closed terms come back only when the
        // lawyer asks for them, to check what was done rather than what is due.
        if ($filter === null || !$filter->showCompleted) {
            $qb->andWhere('d.completed = false');
        }

        if ($filter !== null) {
            $this->applyPillFilter($qb, $filter);
        }

        return $qb;
    }

    /**
     * Narrows the query to the union of the selected triage pills, each of them
     * spelled with the same definition the counters use, so a pill can never list
     * something other than what it counts.
     *
     * The blockage pill has no condition here on purpose: it selects cases whose
     * deadline does not exist yet. Selected on its own it therefore leaves the agenda
     * with nothing to list, which is stated as an impossible condition rather than by
     * silently returning every row.
     */
    private function applyPillFilter(QueryBuilder $qb, DeadlineAgendaFilter $filter): void
    {
        if (!$filter->hasPills()) {
            return;
        }

        $conditions = [];
        foreach ($filter->deadlinePills() as $pill) {
            $conditions[] = match ($pill) {
                DeadlineAgendaFilter::PILL_OVERDUE => 'd.deadlineDate < :pillToday AND d.type NOT IN (:pillUnsanctionedTypes)',
                DeadlineAgendaFilter::PILL_TODAY => 'd.deadlineDate = :pillToday',
                DeadlineAgendaFilter::PILL_FATAL30 => 'd.deadlineDate >= :pillToday AND d.deadlineDate <= :pillHorizon AND d.type IN (:pillFatalTypes)',
            };
        }

        if ($conditions === []) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere('(' . implode(') OR (', $conditions) . ')')
            ->setParameter('pillToday', $this->today(), Types::DATE_IMMUTABLE);

        if ($filter->has(DeadlineAgendaFilter::PILL_OVERDUE)) {
            $qb->setParameter('pillUnsanctionedTypes', $this->consequenceResolver->unsanctionedTypes());
        }

        if ($filter->has(DeadlineAgendaFilter::PILL_FATAL30)) {
            $qb
                ->setParameter('pillHorizon', $this->today()->modify('+30 days'), Types::DATE_IMMUTABLE)
                ->setParameter('pillFatalTypes', $this->consequenceResolver->irreversibleTypes());
        }
    }

    /**
     * Current calendar day at midnight, so every comparison stays on the DATE.
     *
     * Read from the injected clock, not from the system one: the agenda services above
     * this repository take the same clock, and a query that ignored it would make the
     * whole page only half deterministic, with the grouping following the injected day
     * and the SQL filtering following the real one.
     */
    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));
    }
}
