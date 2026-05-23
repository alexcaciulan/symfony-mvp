<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\LegalDeadline;

/**
 * Domain event fired by {@see \App\Service\Deadline\DeadlineAlertService} when a
 * procedural deadline crosses an alert threshold (7 / 3 / 1 days before, or once
 * after it has expired). The service dispatches it and sets the dedup flag; the
 * actual email / in-app notification / Mercure push is left to an event subscriber.
 *
 * Carries the full `LegalDeadline`, so consumers can read `$deadline->getType()`
 * to differentiate procedural deadlines (prorogated) from material-law ones like
 * PRESCRIPTIE (NOT prorogated, NCC art. 2517). `$daysRemaining` is signed:
 * positive = days left, negative = days past the deadline (expired).
 */
final readonly class DeadlineAlertEvent
{
    public function __construct(
        public LegalDeadline $deadline,
        public int $daysRemaining,
    ) {}

    public function isExpired(): bool
    {
        return $this->daysRemaining < 0;
    }
}
