<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * Everything the deadlines page renders, assembled once by
 * {@see DeadlinePageViewBuilder}. Only the active tab carries rows; the counters always
 * describe the whole account.
 */
final readonly class DeadlinePageView
{
    /**
     * @param list<DeadlineGroupView>    $groups        the time buckets of the first tab, in render order
     * @param array{overdue: int, today: int, todo: int, blocked: int, prescriptions: int, completed: int} $counters
     * @param list<DeadlineBlockageView> $blockages     rows of the blocked cases tab
     * @param list<DeadlineRowView>      $prescriptions rows of the limitation tab
     * @param list<DeadlineRowView>      $completed     rows of the closed terms tab
     * @param \DateTimeImmutable         $windowEnd     last day the agenda covers, stated on screen
     * @param DeadlineAgendaFilter       $filter        selection the page was built under
     */
    public function __construct(
        public array $groups,
        public array $counters,
        public array $blockages,
        public array $prescriptions,
        public array $completed,
        public bool $hasMoreCompleted,
        public ?\DateTimeImmutable $nextPrescription,
        public ?\DateTimeImmutable $lastCompleted,
        public \DateTimeImmutable $windowEnd,
        public DeadlineAgendaFilter $filter,
    ) {}

    /** Whether the first tab lists nothing under the current selection. */
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
     * Whether the account has nothing to watch at all. Read from the counters, because
     * only the active tab carries rows and an account holding only closed terms must
     * still reach them.
     */
    public function isNothingTracked(): bool
    {
        return $this->counters['todo'] === 0
            && $this->counters['blocked'] === 0
            && $this->counters['prescriptions'] === 0
            && $this->counters['completed'] === 0;
    }
}
