<?php

declare(strict_types=1);

namespace App\Enum;

/** What the platform holds as evidence that the court mailbox received the message. */
enum FilingEvidenceStatus: string
{
    case AWAITING_RECEIPT = 'AWAITING_RECEIPT';
    case RECEIPT_RECEIVED = 'RECEIPT_RECEIVED';
    case RECEIPT_MANUAL = 'RECEIPT_MANUAL';
    case RECEIPT_OVERDUE = 'RECEIPT_OVERDUE';

    public function label(): string
    {
        return 'enum.filing_evidence_status.' . $this->value;
    }
}
