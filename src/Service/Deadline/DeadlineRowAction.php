<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * The buttons of one agenda row. There is no checkbox: closing a deadline is a
 * labelled button, because a tick reads as "the act was done" while all it does is
 * remove the row from the agenda and silence the alerts.
 *
 * Two shapes only, both decided by {@see DeadlineRowActionResolver}:
 * - the act happens here, so the primary button IS the close and {@see self::$close}
 *   is null, there being no second control;
 * - the act happens elsewhere, so the primary button opens that place and the close
 *   sits next to it as an outlined secondary button, always in the same position.
 *
 * A fatal deadline whose date has passed with the term certain gets no close button
 * at all: the row is dimmed and the only thing left to do is open the case.
 */
final readonly class DeadlineRowAction
{
    public function __construct(
        public DeadlineActionButton $primary,
        public ?DeadlineActionButton $close = null,
    ) {}

    /** Whether the primary button is itself the close, so no second control is rendered. */
    public function primaryClosesDeadline(): bool
    {
        return $this->primary->closesDeadline;
    }

    public function hasCloseButton(): bool
    {
        return $this->close !== null || $this->primary->closesDeadline;
    }
}
