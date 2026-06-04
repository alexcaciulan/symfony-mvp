<?php

declare(strict_types=1);

namespace App\Enum;

enum InvoiceStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return 'enum.invoice_status.' . $this->value;
    }
}
