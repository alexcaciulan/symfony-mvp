<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Enum\DeadlineBlockageReason;

/**
 * One row of the blockage zone: a case whose fatal deadline cannot be computed
 * because the generating fact has no date on it. An agenda can list what exists;
 * this is what is missing from it.
 *
 * Data only, no route: the button of the row is decided by
 * {@see DeadlineBlockageActionResolver} and reaches the template through
 * {@see DeadlineBlockageView}, the same split the agenda rows use. Kept apart so the
 * finder stays a query and can be read as one.
 */
final readonly class DeadlineBlockage
{
    public function __construct(
        public LegalCase $legalCase,
        public DeadlineBlockageReason $reason,
    ) {}
}
