<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of a fiscal invoice within the Romanian e-Factura (SPV) channel.
 *
 * NOT_APPLICABLE = not submitted to SPV (e.g. issued locally by the stub
 * provider). PENDING/SENT/ACCEPTED/REJECTED track the SPV upload once a real
 * accounting provider transmits the XML. ERROR = transmission failed.
 */
enum EInvoiceStatus: string
{
    case NOT_APPLICABLE = 'not_applicable';
    case PENDING = 'pending';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case ERROR = 'error';

    public function label(): string
    {
        return 'enum.einvoice_status.' . $this->value;
    }

    /** Palette token for the status_badge formatter (see tabulator_formatters.js). */
    public function color(): string
    {
        return match ($this) {
            self::NOT_APPLICABLE => 'slate',
            self::PENDING => 'amber',
            self::SENT => 'blue',
            self::ACCEPTED => 'green',
            self::REJECTED, self::ERROR => 'red',
        };
    }
}
