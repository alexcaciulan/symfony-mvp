<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The event a contractual payment term runs from ("plata în 4 zile de la
 * facturare"). Only ISSUE_DATE can be applied by the application, because the
 * invoice date is on the invoice; the other events are not on any document the
 * wizard reads, so their due date stays the lawyer's to set.
 */
enum PaymentTermAnchor: string
{
    case ISSUE_DATE = 'ISSUE_DATE';
    case INVOICE_RECEIPT = 'INVOICE_RECEIPT';
    case DELIVERY = 'DELIVERY';
    case ACCEPTANCE = 'ACCEPTANCE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return 'enum.payment_term_anchor.' . $this->value;
    }
}
