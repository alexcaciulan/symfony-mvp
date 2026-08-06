<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * Result of {@see DeadlineRealignmentService::realign()}: every deadline the run
 * changed or deliberately left alone, plus how many were already correct.
 *
 * The rows that were already aligned are counted and not listed. They are the
 * majority on a second run, which is exactly when the listing has to stay readable.
 */
final readonly class DeadlineRealignmentReport
{
    /** @param list<DeadlineRealignmentChange> $changes */
    public function __construct(
        public array $changes = [],
        public int $alreadyAligned = 0,
        public bool $dryRun = false,
    ) {}

    /** @return list<DeadlineRealignmentChange> */
    public function ofAction(string $action): array
    {
        return array_values(array_filter($this->changes, static fn (DeadlineRealignmentChange $c): bool => $c->action === $action));
    }

    public function countOfAction(string $action): int
    {
        return count($this->ofAction($action));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'moved' => $this->countOfAction(DeadlineRealignmentChange::ACTION_MOVED),
            'removed' => $this->countOfAction(DeadlineRealignmentChange::ACTION_REMOVED),
            'skipped' => $this->countOfAction(DeadlineRealignmentChange::ACTION_SKIPPED),
            'alreadyAligned' => $this->alreadyAligned,
            'changes' => array_map(
                static fn (DeadlineRealignmentChange $c): array => [
                    'action' => $c->action,
                    'deadlineId' => $c->deadlineId,
                    'type' => $c->type,
                    'caseNumber' => $c->caseNumber,
                    'date' => $c->date,
                    'newDate' => $c->newDate,
                    'reason' => $c->reason,
                ],
                $this->changes,
            ),
        ];
    }
}
