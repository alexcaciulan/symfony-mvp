<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which registry (registratura.rejust.ro) form takes the stamp duty, if any yet.
 * The duty is paid in the existing case, so only a file number opens the form; the
 * two states without a URL differ only in the explanation shown to the lawyer.
 */
enum RejustStampDutyForm: string
{
    case NOT_FILED = 'NOT_FILED';
    case AWAITING_CASE_NUMBER = 'AWAITING_CASE_NUMBER';
    case EXISTING_CASE = 'EXISTING_CASE';

    public function url(): ?string
    {
        return match ($this) {
            self::EXISTING_CASE => 'https://registratura.rejust.ro/plata-taxei-judiciare-de-timbru-intr-un-dosar-existent',
            self::NOT_FILED, self::AWAITING_CASE_NUMBER => null,
        };
    }
}
