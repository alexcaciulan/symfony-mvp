<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalDeadline;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlineConsequence;

/**
 * One agenda row: the deadline plus everything derived for it once, so the caller
 * never resolves the same thing per render.
 */
final readonly class DeadlineAgendaItem
{
    public function __construct(
        public LegalDeadline $deadline,
        public DeadlineConsequence $consequence,
        public DeadlineCertainty $certainty,
        /** Whole calendar days from today: negative when the date has passed, 0 today. */
        public int $daysRemaining,
        /**
         * Translation key base of the marker printed under the date, resolved from the
         * type by {@see DeadlineCertaintyResolver::estimateReasonKey()}. Only read when
         * the certainty is ESTIMAT; on a certain date it names the reason that would
         * have applied and nothing renders it.
         */
        public string $estimateReasonKey,
    ) {}

    public function severityRank(): int
    {
        return $this->consequence->severityRank();
    }

    public function isIrreversible(): bool
    {
        return $this->consequence->isIrreversible();
    }

    /**
     * Whether the row must be read as a term whose consequence may already have
     * happened: the miss is irreversible, the date has passed, and the date is the
     * real one. Such a row is dimmed and offers no close button, because closing it
     * would only silence the alerts on the one case where silence costs the claim.
     *
     * An estimated date is deliberately excluded. When the generating fact is
     * unconfirmed the stored date is a working assumption, so nothing can be
     * asserted about a consequence from it. That is exactly the situation of a
     * limitation term on a case whose summons was communicated: the interruption
     * (CPC art. 1015 para. 2, NCC art. 2540) is not modelled, so the term shown is
     * the raw one and the row stays workable.
     */
    public function isConsequenceConsumed(): bool
    {
        return $this->isIrreversible()
            && $this->daysRemaining < 0
            && !$this->certainty->isEstimated();
    }
}
