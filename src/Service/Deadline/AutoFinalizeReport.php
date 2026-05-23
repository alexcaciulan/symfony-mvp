<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * Immutable result of {@see CaseAutoFinalizer::process()}: how many cases were
 * marked DEFINITIVA, skipped for a missing communication date, or not yet due.
 * Used for the `app:check-deadlines` output.
 */
final readonly class AutoFinalizeReport
{
    public function __construct(
        public int $finalized = 0,
        public int $missingCommunicationDate = 0,
        public int $notYetDue = 0,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'finalized' => $this->finalized,
            'missingCommunicationDate' => $this->missingCommunicationDate,
            'notYetDue' => $this->notYetDue,
        ];
    }
}
