<?php

declare(strict_types=1);

namespace App\Enum;

/** The admin's decision on a report: the address stays in use, or it is taken out of use. */
enum CourtEmailReportResolution: string
{
    case DISMISSED = 'DISMISSED';
    case INVALIDATED = 'INVALIDATED';

    public function label(): string
    {
        return 'enum.court_email_report_resolution.' . $this->value;
    }
}
