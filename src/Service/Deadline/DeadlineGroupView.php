<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * A time bucket ready for rendering. Mirrors {@see DeadlineAgendaGroup}, with the
 * rows already carrying their buttons. Empty groups survive the trip, because an
 * empty "today" is a result the page has to show, not an absence.
 */
final readonly class DeadlineGroupView
{
    /** @param list<DeadlineRowView> $rows */
    public function __construct(
        public string $key,
        public array $rows,
    ) {}

    public function count(): int
    {
        return \count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
