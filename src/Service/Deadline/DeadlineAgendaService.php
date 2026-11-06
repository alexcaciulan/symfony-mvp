<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use Psr\Clock\ClockInterface;

/**
 * Shapes the open deadlines of a lawyer into the buckets the agenda page renders.
 * It reads and groups, it never computes a legal term: the dates come from
 * {@see DeadlineService} exactly as stored.
 *
 * Every comparison is done on the calendar DATE. A deadline dated today belongs to
 * "today", not to the overdue bucket, because the term runs until the end of that
 * day (CPC art. 182 para. 1).
 */
final class DeadlineAgendaService
{
    /** Days of look-ahead of the first tab; beyond it the limitation terms have their own tab. */
    public const HORIZON_DAYS = 30;

    public function __construct(
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly DeadlineConsequenceResolver $consequenceResolver,
        private readonly DeadlineCertaintyResolver $certaintyResolver,
        private readonly DeadlineEstimateNoteResolver $estimateNoteResolver,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Groups, in render order: overdue (no lower bound), today, the expired terms of the
     * debtor, the rest of the current week and the remainder of the 30-day horizon.
     *
     * Inside a group the order is by gravity of the consequence first, then by date,
     * because two deadlines falling the same day are only separable by what missing
     * them costs.
     *
     * The filter, when given, narrows every bucket in SQL. The buckets themselves are
     * unchanged: a filter removes rows, it never moves one into another day.
     *
     * @return list<DeadlineAgendaGroup> always the five groups, empty ones included
     */
    public function buildAgenda(User $user, ?DeadlineAgendaFilter $filter = null): array
    {
        $today = $this->today();
        $weekEnd = LegalDeadlineRepository::weekGroupEnd($today);
        $horizon = $today->modify('+' . self::HORIZON_DAYS . ' days');

        $overdue = $this->deadlineRepository->findOverdueForUser($user, $filter);
        $unblocked = $this->deadlineRepository->findExpiredDebtorTermsForUser($user, $filter);
        $upcoming = $this->deadlineRepository->findAgendaForUser($user, $today, $horizon, $filter);

        $spans = $this->prescriptionSpans([...$overdue, ...$upcoming]);

        $buckets = [
            DeadlineAgendaGroup::KEY_OVERDUE => $this->toItems($overdue, $today, $spans),
            DeadlineAgendaGroup::KEY_TODAY => [],
            DeadlineAgendaGroup::KEY_UNBLOCKED => $this->toItems($unblocked, $today, $spans),
            DeadlineAgendaGroup::KEY_WEEK => [],
            DeadlineAgendaGroup::KEY_LATER => [],
        ];

        foreach ($upcoming as $deadline) {
            // Compared as Y-m-d strings: the buckets are calendar days, and string
            // comparison cannot be thrown off by a timezone carried by either side.
            $date = $deadline->getDeadlineDate()->format('Y-m-d');
            $key = match (true) {
                $date === $today->format('Y-m-d') => DeadlineAgendaGroup::KEY_TODAY,
                $date <= $weekEnd->format('Y-m-d') => DeadlineAgendaGroup::KEY_WEEK,
                default => DeadlineAgendaGroup::KEY_LATER,
            };

            $buckets[$key][] = $this->toItem($deadline, $today, $spans);
        }

        $groups = [];
        foreach ($buckets as $key => $items) {
            $groups[] = new DeadlineAgendaGroup($key, $this->sortByGravity($items));
        }

        return $groups;
    }

    /**
     * Prescription terms falling beyond the agenda horizon, shaped like agenda rows. They
     * are kept out of the buckets because every case contributes one on a three-year
     * horizon, which inside the agenda would be permanent noise. Under 30 days they
     * migrate into the agenda on their own, because the repository filters on the same
     * horizon.
     *
     * @return list<DeadlineAgendaItem> ascending by date, the nearest one first
     */
    public function buildLongHorizonPrescriptions(User $user): array
    {
        $deadlines = $this->deadlineRepository->findLongHorizonPrescriptionsForUser($user);

        return $this->toItems($deadlines, $this->today(), $this->prescriptionSpans($deadlines));
    }

    /** @return list<DeadlineAgendaItem> the closed terms, the most recently closed first */
    public function buildCompleted(User $user, int $limit): array
    {
        return $this->toItems($this->deadlineRepository->findCompletedForUser($user, $limit), $this->today(), []);
    }

    /** One deadline shaped like an agenda row, for the side panel. */
    public function buildItem(LegalDeadline $deadline): DeadlineAgendaItem
    {
        return $this->toItem($deadline, $this->today(), $deadline->isCompleted() ? [] : $this->prescriptionSpans([$deadline]));
    }

    /**
     * @param LegalDeadline[] $deadlines
     *
     * @return array<int, array{passed?: array{count: int, last: \DateTimeImmutable}, running?: array{count: int, last: \DateTimeImmutable}}>
     */
    private function prescriptionSpans(array $deadlines): array
    {
        $caseIds = [];
        foreach ($deadlines as $deadline) {
            $caseId = $deadline->getLegalCase()->getId();
            if ($deadline->getType() === DeadlineType::PRESCRIPTIE && $caseId !== null) {
                $caseIds[$caseId] = $caseId;
            }
        }

        return $this->deadlineRepository->summarizeOpenPrescriptionsByCase(array_values($caseIds));
    }

    /**
     * @param LegalDeadline[]                                                                                                                        $deadlines
     * @param array<int, array{passed?: array{count: int, last: \DateTimeImmutable}, running?: array{count: int, last: \DateTimeImmutable}}> $spans
     *
     * @return list<DeadlineAgendaItem>
     */
    private function toItems(array $deadlines, \DateTimeImmutable $today, array $spans): array
    {
        return array_map(fn (LegalDeadline $d): DeadlineAgendaItem => $this->toItem($d, $today, $spans), array_values($deadlines));
    }

    /** @param array<int, array{passed?: array{count: int, last: \DateTimeImmutable}, running?: array{count: int, last: \DateTimeImmutable}}> $spans */
    private function toItem(LegalDeadline $deadline, \DateTimeImmutable $today, array $spans): DeadlineAgendaItem
    {
        $daysRemaining = $this->calendarDaysUntil($today, $deadline->getDeadlineDate());
        $span = $deadline->getType() === DeadlineType::PRESCRIPTIE
            ? ($spans[$deadline->getLegalCase()->getId()][$daysRemaining < 0 ? 'passed' : 'running'] ?? null)
            : null;

        return new DeadlineAgendaItem(
            deadline: $deadline,
            consequence: $this->consequenceResolver->resolveFor($deadline),
            certainty: $this->certaintyResolver->resolveFor($deadline),
            daysRemaining: $daysRemaining,
            estimateNote: $this->estimateNoteResolver->resolveFor($deadline, $span['last'] ?? null),
            coveredTerms: $span['count'] ?? 1,
        );
    }

    /**
     * @param list<DeadlineAgendaItem> $items
     *
     * @return list<DeadlineAgendaItem>
     */
    private function sortByGravity(array $items): array
    {
        usort($items, static function (DeadlineAgendaItem $a, DeadlineAgendaItem $b): int {
            return $b->severityRank() <=> $a->severityRank()
                ?: $a->deadline->getDeadlineDate() <=> $b->deadline->getDeadlineDate();
        });

        return $items;
    }

    /**
     * Whole calendar days between the two dates, signed. Computed on dates rather
     * than on timestamps, so the answer does not depend on the hour of the request
     * the way {@see LegalDeadline::getDaysRemaining()} does.
     */
    private function calendarDaysUntil(\DateTimeImmutable $today, \DateTimeImmutable $deadlineDate): int
    {
        return (int) $today->diff($this->dateOnly($deadlineDate))->format('%r%a');
    }

    private function today(): \DateTimeImmutable
    {
        return $this->dateOnly(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }

    /** Midnight of the same calendar day, rebuilt so no source timezone survives. */
    private function dateOnly(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'));
    }
}
