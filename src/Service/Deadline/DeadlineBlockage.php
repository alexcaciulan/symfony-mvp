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
 * Data only, no route. The row links into the case, where the dialog that records
 * the missing date lives: the routes that record it are POST endpoints, so a link
 * built from one would answer 405, and a real deep link would need the case page to
 * open a dialog from a query parameter, which is page state this zone has no reason
 * to introduce.
 */
final readonly class DeadlineBlockage
{
    public function __construct(
        public LegalCase $legalCase,
        public DeadlineBlockageReason $reason,
    ) {}
}
