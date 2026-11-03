<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why the lawyer took a position out of the claim. Kept on the position and in
 * the audit entry: months later the file has to say why an invoice was not
 * claimed, and a free text alone is not searchable.
 */
enum ClaimExclusionReason: string
{
    case PAID_IN_FULL = 'paid_in_full';
    case ISSUED_IN_ERROR = 'issued_in_error';
    case DISPUTED = 'disputed';
    case OTHER = 'other';

    public function label(): string
    {
        return 'enum.claim_exclusion_reason.' . $this->value;
    }
}
