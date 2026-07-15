<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Outcome of resolving the town hall (UAT) that collects the stamp duty. Every
 * non-resolved case is actionable by the lawyer, never a silent fallback: naming
 * the wrong UAT carries the same risk as naming the wrong bank account.
 */
enum StampDutyTargetStatus: string
{
    case RESOLVED = 'RESOLVED';

    /** The creditor has no structured county/locality yet (fill it on the creditor record). */
    case LOCATION_MISSING = 'LOCATION_MISSING';

    /**
     * County and locality are set but match no Romanian UAT. Either the spelling
     * differs from the official catalogue, or the claimant is seated abroad, in
     * which case the duty goes to the UAT of the court's seat (art. 40 alin. 2).
     */
    case UNMATCHED = 'UNMATCHED';

    public function label(): string
    {
        return 'enum.stamp_duty_target_status.' . $this->value;
    }
}
