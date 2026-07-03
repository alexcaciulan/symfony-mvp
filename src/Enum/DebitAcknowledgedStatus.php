<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Debt status confirmed by the lawyer before generating the payment-order
 * petition: partially paid or unpaid. Required for the OP-generation consent
 * (prior procedure, CPC art. 1015-1016).
 */
enum DebitAcknowledgedStatus: string
{
    case PARTIAL = 'PARTIAL';
    case UNPAID = 'UNPAID';

    public function label(): string
    {
        return 'enum.debit_acknowledged_status.' . $this->value;
    }
}
