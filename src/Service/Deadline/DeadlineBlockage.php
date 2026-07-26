<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Enum\DeadlineBlockageReason;

/**
 * One row of the blockage zone: a case whose fatal deadline cannot be computed
 * because the generating fact has no date on it. An agenda can list what exists;
 * this is what is missing from it.
 */
final readonly class DeadlineBlockage
{
    public function __construct(
        public LegalCase $legalCase,
        public DeadlineBlockageReason $reason,
    ) {}

    public function actionRoute(): string
    {
        return $this->reason->actionRoute();
    }

    /** @return array<string, int|null> */
    public function actionRouteParameters(): array
    {
        return [$this->reason->actionRouteParameterName() => $this->legalCase->getId()];
    }
}
