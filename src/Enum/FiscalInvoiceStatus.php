<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Document lifecycle of a fiscal invoice, distinct from its SPV state
 * ({@see EInvoiceStatus}) and from the legacy billing record ({@see App\Enum\InvoiceStatus}).
 *
 * DRAFT = built but not yet issued (no series/number). ISSUED = series/number
 * allocated, document final. CANCELED = annulled through a storno.
 */
enum FiscalInvoiceStatus: string
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return 'enum.fiscal_invoice_status.' . $this->value;
    }

    /** Palette token for the status_badge formatter (see tabulator_formatters.js). */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'slate',
            self::ISSUED => 'green',
            self::CANCELED => 'red',
        };
    }
}
