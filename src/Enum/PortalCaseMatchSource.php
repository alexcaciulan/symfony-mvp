<?php

declare(strict_types=1);

namespace App\Enum;

/** Which search found a court case on portal.just.ro. */
enum PortalCaseMatchSource: string
{
    /** The daily search (app:portal-discover-cases). */
    case AUTO = 'auto';
    /** The search the lawyer started from the case's portal tab. */
    case MANUAL = 'manual';
}
