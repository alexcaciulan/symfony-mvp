<?php

declare(strict_types=1);

namespace App\Enum;

enum CourtEmailStatus: string
{
    /** Found in a source or typed in, not yet confirmed by a lawyer or an admin. */
    case PROPOSED = 'PROPOSED';
    /** Confirmed by a lawyer or an admin through the filing statement. */
    case VALIDATED = 'VALIDATED';
    case REJECTED = 'REJECTED';
    /** Was in use and turned out wrong (bounce, report, admin decision). */
    case INVALID = 'INVALID';

    public function label(): string
    {
        return 'enum.court_email_status.' . $this->value;
    }
}
