<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * One line of what {@see DeadlineRealignmentService} did, or would have done, to a
 * single deadline. Flat strings rather than entities: the report is printed by a
 * console command and, on a dry run, describes rows that were never touched.
 */
final readonly class DeadlineRealignmentChange
{
    public const ACTION_MOVED = 'moved';
    public const ACTION_REMOVED = 'removed';
    public const ACTION_SKIPPED = 'skipped';

    /** The case carries no date the term could run from, so nothing can be proven about it. */
    public const REASON_ANCHOR_MISSING = 'anchor_missing';

    /**
     * The stored date is not what the previous rule would have produced from the
     * anchor, so it was put there by a human or by a fixture and is left alone.
     */
    public const REASON_NOT_FROM_PREVIOUS_RULE = 'not_from_previous_rule';

    public function __construct(
        public string $action,
        public int $deadlineId,
        public string $type,
        public string $caseNumber,
        public string $date,
        public ?string $newDate = null,
        public ?string $reason = null,
    ) {}

    public function describe(): string
    {
        return match ($this->action) {
            self::ACTION_MOVED => sprintf('#%d %s (%s): %s -> %s', $this->deadlineId, $this->type, $this->caseNumber, $this->date, (string) $this->newDate),
            self::ACTION_REMOVED => sprintf('#%d %s (%s): %s removed', $this->deadlineId, $this->type, $this->caseNumber, $this->date),
            default => sprintf('#%d %s (%s): %s kept, %s', $this->deadlineId, $this->type, $this->caseNumber, $this->date, (string) $this->reason),
        };
    }
}
