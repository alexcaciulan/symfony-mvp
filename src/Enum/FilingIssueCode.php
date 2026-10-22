<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Codes of the pieces the filing checks for. The values double as the suffix of
 * the translation keys of the refusals and of the generation dialog.
 */
enum FilingIssueCode: string
{
    case POWER_OF_ATTORNEY_MISSING = 'power_of_attorney_missing';
    case POWER_OF_ATTORNEY_DATE_MISSING = 'power_of_attorney_date_missing';
    case POWER_OF_ATTORNEY_EXTRA_DATE_MISSING = 'power_of_attorney_extra_date_missing';
    case POWER_OF_ATTORNEY_AFTER_SUMMONS = 'power_of_attorney_after_summons';
    case CLAIM_DOCUMENT_MISSING = 'claim_document_missing';
    case CONTRACT_MISSING = 'contract_missing';
}
