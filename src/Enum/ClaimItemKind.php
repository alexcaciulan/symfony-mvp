<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a claim position actually is. Drives nothing in the arithmetic (every
 * kind accrues interest from its own due date) but it is what the petition
 * must state as the basis of each sum (CPC art. 1016 alin. 1 lit. c).
 */
enum ClaimItemKind: string
{
    case INVOICE = 'invoice';
    case CONTRACT_INSTALMENT = 'contract_instalment';
    /** Storno: reduces the claim instead of adding to it. */
    case CREDIT_NOTE = 'credit_note';
    case OTHER = 'other';

    public function label(): string
    {
        return 'enum.claim_item_kind.' . $this->value;
    }
}
