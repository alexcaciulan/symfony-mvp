<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A fixed list on purpose: a free-text report could carry data about the case or
 * about court staff to the admin.
 */
enum CourtEmailReportReason: string
{
    case WRONG_COURT = 'WRONG_COURT';
    case BOUNCED = 'BOUNCED';
    case NOT_ACCEPTING_FILINGS = 'NOT_ACCEPTING_FILINGS';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return 'enum.court_email_report_reason.' . $this->value;
    }
}
