<?php

declare(strict_types=1);

namespace App\Enum;

/** Who established the outcome of an attempt. */
enum FilingAttemptSource: string
{
    case SMTP = 'SMTP';
    case ASYNC_BOUNCE = 'ASYNC_BOUNCE';
    case LAWYER_CC_CHECK = 'LAWYER_CC_CHECK';
    case ADMIN_OVERRIDE = 'ADMIN_OVERRIDE';

    public function label(): string
    {
        return 'enum.filing_attempt_source.' . $this->value;
    }
}
