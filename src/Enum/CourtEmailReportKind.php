<?php

declare(strict_types=1);

namespace App\Enum;

/** Who raised a report on a court address: a lawyer, or a delivery report received after sending. */
enum CourtEmailReportKind: string
{
    case LAWYER = 'LAWYER';
    case DELIVERY_REPORT = 'DELIVERY_REPORT';

    public function label(): string
    {
        return 'enum.court_email_report_kind.' . $this->value;
    }
}
