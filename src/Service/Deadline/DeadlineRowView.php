<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;

/**
 * One rendered row: the deadline with everything derived for it, plus the buttons
 * already decided. The template reads properties, it decides nothing.
 */
final readonly class DeadlineRowView
{
    public function __construct(
        public DeadlineAgendaItem $item,
        public DeadlineRowAction $action,
        /** What the lawyer must read before closing this row; null when the miss is reversible. */
        public ?DeadlineCloseConfirmation $closeConfirmation = null,
    ) {}

    public function deadline(): LegalDeadline
    {
        return $this->item->deadline;
    }

    public function legalCase(): LegalCase
    {
        return $this->item->deadline->getLegalCase();
    }

    /** Dimmed row, no close button, wording that says the consequence may have occurred. */
    public function isConsequenceConsumed(): bool
    {
        return $this->item->isConsequenceConsumed();
    }

    /** Marked under the date as short text, never as a second badge. */
    public function isEstimated(): bool
    {
        return $this->item->certainty->isEstimated();
    }
}
