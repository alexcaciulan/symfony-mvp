<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Reasons available when marking a case as rejected. The set is deliberately
 * narrow: partial admission of the payment-order petition is NOT modelled
 * here because admiterea parțială under CPC art. 1022 produces a payment
 * order (ORDONANTA_EMISA) for the granted portion, not a rejection.
 * Treating partial admission as a rejection would lose the writ of
 * execution for the granted amount.
 */
enum RejectReason: string
{
    case NO_PROOF = 'NO_PROOF';
    case INADMISSIBLE = 'INADMISSIBLE';

    public function label(): string
    {
        return 'enum.reject_reason.' . $this->value;
    }
}
