<?php

declare(strict_types=1);

namespace App\Enum;

/** How a party read off an uploaded invoice compares with the one on the case. */
enum PartyMatchStatus: string
{
    /** Both fiscal codes are present and name the same company. */
    case MATCH = 'match';
    /** Both fiscal codes are present and name different companies. */
    case MISMATCH = 'mismatch';
    /** One of the codes is missing: only the names can be compared. */
    case UNKNOWN = 'unknown';
}
