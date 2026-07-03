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

    /** Palette token for the status_badge formatter (see tabulator_formatters.js). */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'amber',
            self::PAID => 'green',
            self::CANCELED => 'slate',
        };
    }
}
