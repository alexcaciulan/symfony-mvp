<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalDeadline;
use App\Entity\User;
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
    /** Days of look-ahead in the agenda body; beyond it only the rail matters. */
    public const HORIZON_DAYS = 30;

    public function __construct(
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly DeadlineConsequenceResolver $consequenceResolver,
        private readonly DeadlineCertaintyResolver $certaintyResolver,
        private readonly DeadlineEstimateNoteResolver $estimateNoteResolver,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Groups, in render order: overdue (no lower bound), the expired terms of the
     * debtor, today, tomorrow, the rest of the current week (through Sunday) and the
     * remainder of the 30-day horizon.
     *
     * Inside a group the order is by gravity of the consequence first, then by date,
     * because two deadlines falling the same day are only separable by what missing
     * them costs.
     *
     * The filter, when given, narrows every bucket in SQL. The buckets themselves are
     * unchanged: a filter removes rows, it never moves one into another day.
     *
     * @return list<DeadlineAgendaGroup> always the six groups, empty ones included
     */
    public function buildAgenda(User $user, ?DeadlineAgendaFilter $filter = null): array
    {
        $today = $this->today();
        $tomorrow = $today->modify('+1 day');
        $endOfWeek = $this->endOfWeek($today);
        $horizon = $today->modify('+' . self::HORIZON_DAYS . ' days');

        $buckets = [
            DeadlineAgendaGroup::KEY_OVERDUE => $this->toItems($this->deadlineRepository->findOverdueForUser($user, $filter), $today),
            DeadlineAgendaGroup::KEY_UNBLOCKED => $this->toItems($this->deadlineRepository->findExpiredDebtorTermsForUser($user, $filter), $today),
            DeadlineAgendaGroup::KEY_TODAY => [],
            DeadlineAgendaGroup::KEY_TOMORROW => [],
            DeadlineAgendaGroup::KEY_REST_OF_WEEK => [],
            DeadlineAgendaGroup::KEY_NEXT_30 => [],
        ];

        foreach ($this->deadlineRepository->findAgendaForUser($user, $today, $horizon, $filter) as $deadline) {
            // Compared as Y-m-d strings: the buckets are calendar days, and string
            // comparison cannot be thrown off by a timezone carried by either side.
            $date = $deadline->getDeadlineDate()->format('Y-m-d');
            $key = match (true) {
                $date === $today->format('Y-m-d') => DeadlineAgendaGroup::KEY_TODAY,
                $date === $tomorrow->format('Y-m-d') => DeadlineAgendaGroup::KEY_TOMORROW,
                $date <= $endOfWeek->format('Y-m-d') => DeadlineAgendaGroup::KEY_REST_OF_WEEK,
                default => DeadlineAgendaGroup::KEY_NEXT_30,
            };

            $buckets[$key][] = $this->toItem($deadline, $today);
        }

        $groups = [];
        foreach ($buckets as $key => $items) {
            $groups[] = new DeadlineAgendaGroup($key, $this->sortByGravity($items));
        }

        return $groups;
    }

    /**
     * Prescription terms falling beyond the agenda horizon, shaped like agenda rows
     * so the rail renders with the same component. They are deliberately kept out of
     * the buckets and out of the counters: every case contributes one to two open
     * prescriptions on a three-year horizon, which inside the agenda would be
     * permanent noise. Under 30 days they migrate into the body on their own, by the
     * single window rule, because the repository filters on the same horizon.
     *
     * @return list<DeadlineAgendaItem> ascending by date, the nearest one first
     */
    public function buildLongHorizonPrescriptions(User $user, int $limit = 5, ?DeadlineAgendaFilter $filter = null): array
    {
        return $this->toItems(
            $this->deadlineRepository->findLongHorizonPrescriptionsForUser($user, $limit, $filter),
            $this->today(),
        );
    }

    /**
     * @param LegalDeadline[] $deadlines
     *
     * @return list<DeadlineAgendaItem>
     */
    private function toItems(array $deadlines, \DateTimeImmutable $today): array
    {
        return array_map(fn (LegalDeadline $d): DeadlineAgendaItem => $this->toItem($d, $today), array_values($deadlines));
    }

    private function toItem(LegalDeadline $deadline, \DateTimeImmutable $today): DeadlineAgendaItem
    {
        return new DeadlineAgendaItem(
            deadline: $deadline,
            consequence: $this->consequenceResolver->resolveFor($deadline),
            certainty: $this->certaintyResolver->resolveFor($deadline),
            daysRemaining: $this->calendarDaysUntil($today, $deadline->getDeadlineDate()),
            estimateNote: $this->estimateNoteResolver->resolveFor($deadline),
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

    /** Sunday of the week containing $today; equals $today when it already is Sunday. */
    private function endOfWeek(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->modify('+' . (7 - (int) $today->format('N')) . ' days');
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
