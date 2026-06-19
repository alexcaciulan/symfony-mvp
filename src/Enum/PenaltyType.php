<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Accessory computation mode for a payment notice (somație de plată).
 *
 *  - LEGAL_PENALIZATOARE: statutory penalty interest, NBR reference rate + 8
 *    percentage points for relations between professionals (OG 13/2011 art. 3
 *    para. 2¹, introduced by Law 72/2013 art. 20).
 *  - CONTRACTUAL: penalty clause with a daily rate agreed by the parties
 *    (Civil Code art. 1538), e.g. 0.10%/day of delay.
 */
enum PenaltyType: string
{
    case LEGAL_PENALIZATOARE = 'LEGAL_PENALIZATOARE';
    case CONTRACTUAL = 'CONTRACTUAL';

    public function label(): string
    {
        return 'enum.penalty_type.' . $this->value;
    }
}
