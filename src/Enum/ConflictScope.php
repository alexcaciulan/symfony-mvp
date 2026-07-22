<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What part of the prefilled case a conflict is about, which is also where the
 * wizard has to surface it.
 */
enum ConflictScope: string
{
    case CREDITOR = 'creditor';
    case DEBTOR = 'debtor';
    case CLAIM = 'claim';
    case CLAIM_ITEM = 'claim_item';

    /** About the set of debtors as a whole rather than one of them. */
    case DEBTOR_SET = 'debtor_set';
}
