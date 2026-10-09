<?php

declare(strict_types=1);

namespace App\Enum;

/** Where a court case found on portal.just.ro stands with the lawyer. */
enum PortalCaseMatchStatus: string
{
    /** Found and shown on the case, waiting for the lawyer to decide. */
    case PROPOSED = 'proposed';
    /** Its number became the case's confirmed court case number. */
    case ACCEPTED = 'accepted';
    /** Not this case: never proposed again. */
    case DISMISSED = 'dismissed';
}
