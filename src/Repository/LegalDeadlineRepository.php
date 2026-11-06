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
     * Open deadlines of one case, of every type, earliest first.
     *
     * @return LegalDeadline[]
     */
    public function findIncompleteByCase(LegalCase $case): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.legalCase = :case')
            ->andWhere('d.completed = false')
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

    /**
     * Open deadlines of the given types on live, non-terminal cases, for the one-off
     * realignment command. Global, not scoped per user; soft-deleted cases excluded.
     *
     * Terminal cases are left out because nothing is acted on there any more: moving a
     * date on a rejected or closed case would rewrite history without changing what
     * anyone has to do.
     *
     * @param list<DeadlineType> $types
     *
     * @return LegalDeadline[]
     */
    public function findOpenByTypesOnLiveCases(array $types): array
    {
        $terminal = array_values(array_filter(CaseStatus::cases(), static fn (CaseStatus $s): bool => $s->isTerminal()));

        return $this->createQueryBuilder('d')
            ->join('d.legalCase', 'lc')
            ->addSelect('lc')
            ->andWhere('d.completed = false')
            ->andWhere('d.type IN (:types)')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status NOT IN (:terminal)')
            ->setParameter('types', $types)
            ->setParameter('terminal', $terminal)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open annulment-request terms on cases that carry the date they run from, for the
     * cron that closes them once the ten days of CPC art. 1024 para. 1 have lapsed.
     * Global, not scoped per user; soft-deleted cases excluded.
     *
     * Cases without `rulingCommunicationDate` are left out because the term cannot be
     * proven lapsed without it: they are handled as a blockage instead, which is what
     * asks for that very date.
     *
     * @return LegalDeadline[]
     */
    public function findOpenAppealDeadlines(): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.legalCase', 'lc')
            ->addSelect('lc')
            ->andWhere('d.completed = false')
            ->andWhere('d.type = :type')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.rulingCommunicationDate IS NOT NULL')
            ->setParameter('type', DeadlineType::CERERE_IN_ANULARE)
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
     * sidebar badge and the agenda box are the same number shown in three places, and the dashboard card links straight to the agenda, so a second
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
            ->leftJoin('debtors.debtor', 'debtorCompany')->addSelect('debtorCompany')
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
     * filing the payment order (CPC art. 1015-1017).
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
     * The numbers of the agenda in a single aggregate SELECT. All of them share the base
     * of {@see self::agendaQueryBuilder()}, so they count the rows the page lists.
     *
     * - overdue: date strictly before today, minus the terms of the debtor, for the
     *   reason spelled out on {@see self::findOverdueForUser()}; the box and the sidebar
     *   badge must count exactly what the red section lists
     * - today: date exactly today
     * - week: after today, up to the end of the week group
     * - todo: everything inside the agenda window, debtor terms included
     *
     * Deliberately not filtered: the numbers describe the whole open agenda whatever is
     * selected, because a counter that shrank to the selection would measure the click
     * instead of the risk.
     *
     * @return array{overdue: int, today: int, week: int, todo: int}
     */
    public function countAgendaBuckets(User $user): array
    {
        $today = $this->today();

        $row = $this->agendaQueryBuilder($user)
            ->select(
                'SUM(CASE WHEN d.deadlineDate < :today AND d.type NOT IN (:unsanctionedTypes) THEN 1 ELSE 0 END) AS overdue',
                'SUM(CASE WHEN d.deadlineDate = :today THEN 1 ELSE 0 END) AS today',
                'SUM(CASE WHEN d.deadlineDate > :today AND d.deadlineDate <= :weekEnd THEN 1 ELSE 0 END) AS week',
                'SUM(CASE WHEN d.deadlineDate <= :horizon THEN 1 ELSE 0 END) AS todo',
            )
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->setParameter('weekEnd', self::weekGroupEnd($today), Types::DATE_IMMUTABLE)
            ->setParameter('horizon', $today->modify('+30 days'), Types::DATE_IMMUTABLE)
            ->setParameter('unsanctionedTypes', $this->consequenceResolver->unsanctionedTypes())
            ->getQuery()
            ->getSingleResult();

        return [
            'overdue' => (int) $row['overdue'],
            'today' => (int) $row['today'],
            'week' => (int) $row['week'],
            'todo' => (int) $row['todo'],
        ];
    }

    /**
     * Last day of the "this week" group. Never before tomorrow: on a Sunday the week ends
     * today, and a term due on Monday must not fall into the group that folds.
     */
    public static function weekGroupEnd(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $sunday = $today->modify('+' . (7 - (int) $today->format('N')) . ' days');
        $tomorrow = $today->modify('+1 day');

        return max($sunday, $tomorrow);
    }

    /** @return array{count: int, next: ?\DateTimeImmutable} */
    public function summarizeLongHorizonPrescriptions(User $user): array
    {
        $row = $this->longHorizonQueryBuilder($user)
            ->select('COUNT(d.id) AS total', 'MIN(d.deadlineDate) AS nextDate')
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) $row['total'],
            'next' => $row['nextDate'] !== null ? new \DateTimeImmutable((string) $row['nextDate']) : null,
        ];
    }

    /** @return array{count: int, last: ?\DateTimeImmutable} */
    public function summarizeCompleted(User $user): array
    {
        $row = $this->scopedQueryBuilder($user)
            ->select('COUNT(d.id) AS total', 'MAX(d.completedAt) AS lastClosed')
            ->andWhere('d.completed = true')
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) $row['total'],
            'last' => $row['lastClosed'] !== null ? new \DateTimeImmutable((string) $row['lastClosed']) : null,
        ];
    }

    /**
     * Closed terms, the most recently closed first. Rows closed before the closing date
     * was stored have none, and MySQL puts them last.
     *
     * @return LegalDeadline[]
     */
    public function findCompletedForUser(User $user, int $limit): array
    {
        $deadlines = $this->scopedQueryBuilder($user)
            ->addSelect('lc', 'court')
            ->leftJoin('lc.court', 'court')
            ->andWhere('d.completed = true')
            ->orderBy('d.completedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $this->warmDebtors($deadlines);

        return $deadlines;
    }

    /**
     * How many open limitation terms each case has on each side of today and when the
     * last of them falls: a row stands for the terms of its side, so it can say how many
     * due dates it covers.
     *
     * @param list<int> $caseIds
     *
     * @return array<int, array{passed?: array{count: int, last: \DateTimeImmutable}, running?: array{count: int, last: \DateTimeImmutable}}>
     */
    public function summarizeOpenPrescriptionsByCase(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.legalCase) AS caseId', 'd.deadlineDate AS dueDate')
            ->where('d.legalCase IN (:cases)')
            ->andWhere('d.type = :type')
            ->andWhere('d.completed = false')
            ->orderBy('d.deadlineDate', 'ASC')
            ->setParameter('cases', $caseIds)
            ->setParameter('type', DeadlineType::PRESCRIPTIE)
            ->getQuery()
            ->getArrayResult();

        $today = $this->today();
        $summary = [];
        foreach ($rows as $row) {
            $due = \DateTimeImmutable::createFromInterface($row['dueDate']);
            $side = $due < $today ? 'passed' : 'running';
            $count = $summary[(int) $row['caseId']][$side]['count'] ?? 0;
            $summary[(int) $row['caseId']][$side] = ['count' => $count + 1, 'last' => $due];
        }

        return $summary;
    }

    /**
     * The open limitation terms a row stands for, nearest first, for the list inside the
     * panel: those of the same case on the same side of today.
     *
     * @return LegalDeadline[]
     */
    public function findPrescriptionsMergedWith(LegalDeadline $lead): array
    {
        $today = $this->today();

        return $this->createQueryBuilder('d')
            ->where('d.legalCase = :case')
            ->andWhere('d.type = :type')
            ->andWhere('d.completed = false')
            ->andWhere($lead->getDeadlineDate() < $today ? 'd.deadlineDate < :today' : 'd.deadlineDate >= :today')
            ->setParameter('case', $lead->getLegalCase())
            ->setParameter('type', DeadlineType::PRESCRIPTIE)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->orderBy('d.deadlineDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Prescription deadlines further out than the agenda horizon (today+30 days). They
     * never show up in the agenda body, yet missing one extinguishes the right itself
     * (NCC art. 2517, CPC art. 705 para. 1), so the page keeps them in a tab of their own.
     *
     * @return LegalDeadline[] ascending by deadline date
     */
    public function findLongHorizonPrescriptionsForUser(User $user): array
    {
        $deadlines = $this->longHorizonQueryBuilder($user)
            ->addSelect('lc', 'court')
            ->leftJoin('lc.court', 'court')
            ->orderBy('d.deadlineDate', 'ASC')
            ->getQuery()
            ->getResult();

        $this->warmDebtors($deadlines);

        return $deadlines;
    }

    private function longHorizonQueryBuilder(User $user): QueryBuilder
    {
        return $this->agendaQueryBuilder($user)
            ->andWhere('d.type IN (:prescriptionTypes)')
            ->andWhere('d.deadlineDate > :horizon')
            ->setParameter('prescriptionTypes', self::PRESCRIPTION_TYPES)
            ->setParameter('horizon', $this->today()->modify('+30 days'), Types::DATE_IMMUTABLE);
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
            ->createQuery(sprintf('SELECT c, debtors, company FROM %s c LEFT JOIN c.debtors debtors LEFT JOIN debtors.debtor company WHERE c.id IN (:ids)', LegalCase::class))
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
            ->leftJoin('debtors.debtor', 'debtorCompany')->addSelect('debtorCompany')
            ->andWhere('d.deadlineDate < :today')
            ->setParameter('today', $this->today(), Types::DATE_IMMUTABLE)
            ->orderBy('d.deadlineDate', 'ASC');
    }

    /**
     * Base filter of every agenda query: the deadline is still open and belongs to the
     * user's live, non-terminal case.
     *
     * A case has one limitation term per due date, and the page merges them into at most
     * two rows: one for the terms that ran out and one for those still running, each led
     * by its earliest term. A term that can still be saved never hides under one that is
     * gone. Applying that here rather than after the fetch is what keeps the lists, the
     * boxes, the navigation badge and the overdue card of the dashboard on the same number.
     * The dashboard's upcoming list and KPI count every term on purpose: they are not a
     * reading of this agenda.
     *
     * Passing no filter leaves the query as the counters need it. The fatal selection
     * keeps the irreversible types, past due ones included.
     */
    private function agendaQueryBuilder(User $user, ?DeadlineAgendaFilter $filter = null): QueryBuilder
    {
        $qb = $this->scopedQueryBuilder($user)
            ->andWhere('d.completed = false')
            ->andWhere('d.type <> :mergedType OR NOT EXISTS (
                SELECT earlier.id FROM ' . LegalDeadline::class . ' earlier
                WHERE earlier.legalCase = d.legalCase
                  AND earlier.type = :mergedType
                  AND earlier.completed = false
                  AND (earlier.deadlineDate < d.deadlineDate OR (earlier.deadlineDate = d.deadlineDate AND earlier.id < d.id))
                  AND (d.deadlineDate < :mergeToday OR earlier.deadlineDate >= :mergeToday)
            )')
            ->setParameter('mergedType', DeadlineType::PRESCRIPTIE)
            ->setParameter('mergeToday', $this->today(), Types::DATE_IMMUTABLE);

        if ($filter !== null && $filter->fatalOnly) {
            $qb->andWhere('d.type IN (:fatalTypes)')
                ->setParameter('fatalTypes', $this->consequenceResolver->irreversibleTypes());
        }

        return $qb;
    }

    /** The account boundary: the user's live cases, never a closed or deleted one. */
    private function scopedQueryBuilder(User $user): QueryBuilder
    {
        $terminal = array_filter(
            CaseStatus::cases(),
            static fn (CaseStatus $s): bool => $s->isTerminal(),
        );

        return $this->createQueryBuilder('d')
            ->join('d.legalCase', 'lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('lc.status NOT IN (:terminal)')
            ->setParameter('user', $user)
            ->setParameter('terminal', $terminal);
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
