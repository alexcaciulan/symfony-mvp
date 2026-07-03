<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Kind of fiscal document. STORNO is a correction invoice (mirror with negative
 * amounts) that cancels a previously issued INVOICE while keeping both numbers.
 */
enum FiscalInvoiceKind: string
{
    case INVOICE = 'invoice';
    case STORNO = 'storno';

    public function label(): string
    {
        return 'enum.fiscal_invoice_kind.' . $this->value;
    }
}
