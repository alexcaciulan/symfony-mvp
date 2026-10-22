<?php

declare(strict_types=1);

namespace App\Enum;

/** Why a case has no filing address, each one pointing at the action that fixes it. */
enum CourtFilingAddressMissingReason: string
{
    case NO_COURT = 'NO_COURT';
    case NO_ADDRESS = 'NO_ADDRESS';
    case NOT_VALIDATED = 'NOT_VALIDATED';
    case VALIDATION_EXPIRED = 'VALIDATION_EXPIRED';
    case NO_FILING_DEFAULT = 'NO_FILING_DEFAULT';
    case TRIBUNAL_SECTION_UNKNOWN = 'TRIBUNAL_SECTION_UNKNOWN';

    public function label(): string
    {
        return 'enum.court_filing_address_missing_reason.' . $this->value;
    }

    /** @return 'add'|'validate'|'revalidate'|'none' */
    public function action(): string
    {
        return match ($this) {
            self::NO_ADDRESS => 'add',
            self::NOT_VALIDATED, self::NO_FILING_DEFAULT, self::TRIBUNAL_SECTION_UNKNOWN => 'validate',
            self::VALIDATION_EXPIRED => 'revalidate',
            self::NO_COURT => 'none',
        };
    }
}
