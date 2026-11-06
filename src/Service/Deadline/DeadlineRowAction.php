<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * The two controls of one agenda row, decided by {@see DeadlineRowActionResolver}: the
 * way into the case, always present, and the close, when the term can be closed from the
 * agenda. There is no checkbox and no tick: closing removes the row and silences the
 * alerts, it does not state that the act was done.
 */
final readonly class DeadlineRowAction
{
    public function __construct(
        public DeadlineActionButton $open,
        public ?DeadlineActionButton $close = null,
    ) {}
}
