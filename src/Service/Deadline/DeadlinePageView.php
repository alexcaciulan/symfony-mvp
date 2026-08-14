<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * Everything the deadlines page renders, assembled once by
 * {@see DeadlinePageViewBuilder}. The controller passes it to the template as is.
 */
final readonly class DeadlinePageView
{
    /**
     * @param list<DeadlineGroupView> $groups         the six time buckets, in render order
     * @param array{overdue: int, today: int, fatal30: int, blocked: int} $counters risk bar
     * @param list<DeadlineBlockageView> $blockages   cases whose fatal term cannot be computed
     * @param list<DeadlineRowView>   $longHorizonPrescriptions rail, beyond the agenda window
     * @param \DateTimeImmutable      $windowEnd      last day the agenda covers, stated on screen
     * @param DeadlineAgendaFilter    $filter         selection the page was built under
     */
    public function __construct(
        public array $groups,
        public array $counters,
        public array $blockages,
        public array $longHorizonPrescriptions,
        public \DateTimeImmutable $windowEnd,
        public DeadlineAgendaFilter $filter,
    ) {}

    /**
     * The day the page was built for, derived from the window it covers rather than
     * read again from a clock, so every date printed on the page belongs to the same
     * moment as the rows.
     */
    public function today(): \DateTimeImmutable
    {
        return $this->windowEnd->modify('-' . DeadlineAgendaService::HORIZON_DAYS . ' days');
    }

    /** Whether the agenda window holds nothing at all, blockages aside. */
    public function isAgendaEmpty(): bool
    {
        foreach ($this->groups as $group) {
            if (!$group->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the account has nothing at all to watch, which is a different screen
     * from an empty window. A selection is excluded on purpose: an empty result under
     * a pill says something about the pill, never about the account.
     */
    public function isNothingTracked(): bool
    {
        return !$this->filter->hasPills()
            && $this->isAgendaEmpty()
            && $this->blockages === []
            && $this->longHorizonPrescriptions === [];
    }
}
