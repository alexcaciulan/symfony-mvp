<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * A time bucket of the agenda, in the order the page renders them. Empty groups are
 * returned too, so the caller decides whether an empty bucket deserves a state of
 * its own or is simply skipped.
 */
final readonly class DeadlineAgendaGroup
{
    public const KEY_OVERDUE = 'overdue';
    public const KEY_TODAY = 'today';

    /**
     * Past-due terms of the debtor. They read as late on a calendar and the opposite way
     * in law: their expiry is the event that opens the filing of the request (CPC art.
     * 1015-1017), so they sit apart from the arrears.
     */
    public const KEY_UNBLOCKED = 'unblocked';
    public const KEY_WEEK = 'week';
    public const KEY_LATER = 'later';

    /** @param list<DeadlineAgendaItem> $items */
    public function __construct(
        public string $key,
        public array $items,
    ) {}

    public function count(): int
    {
        return \count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
