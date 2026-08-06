<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\LegalCase;
use App\Enum\BlockedCaseAlert;

/**
 * Domain event fired by {@see \App\Service\Deadline\BlockedCaseAlertService} for a case
 * that needs an act from the lawyer while no deadline is counting down on it.
 *
 * The dedup key is built by the producer, not by the consumer, because it encodes the
 * cadence and only the producer knows the day the run happened. It is what keeps a
 * standing condition from producing a message every single day: the key names the
 * case, the reason and the period, so a second run inside the same period delivers
 * nothing.
 */
final readonly class BlockedCaseAlertEvent
{
    public function __construct(
        public LegalCase $case,
        public BlockedCaseAlert $reason,
        public ?string $dedupKey = null,
    ) {}
}
