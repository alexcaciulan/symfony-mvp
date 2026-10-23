<?php

declare(strict_types=1);

namespace App\Enum;

/** Why a case has no filing address. */
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

    /**
     * What the lawyer can do about it from the case. Only an admin confirms an
     * address, so the lawyer can only suggest one when the court has none.
     *
     * @return 'suggest'|'none'
     */
    public function action(): string
    {
        return $this === self::NO_ADDRESS ? 'suggest' : 'none';
    }
}
