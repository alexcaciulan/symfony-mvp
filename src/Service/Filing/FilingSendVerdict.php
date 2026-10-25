<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\CourtEmailAddress;
use App\Entity\FilingPackage;

/** What stands between a case and its transmission to the court, in the order to fix it. */
final readonly class FilingSendVerdict
{
    /**
     * @param list<string>                                           $blockers translation keys
     * @param list<array{key: string, params: array<string, string>}> $warnings
     */
    public function __construct(
        public array $blockers,
        public array $warnings,
        public ?CourtEmailAddress $address,
        public ?FilingPackage $package,
    ) {}

    public function isAllowed(): bool
    {
        return $this->blockers === [];
    }

    public function firstBlocker(): ?string
    {
        return $this->blockers[0] ?? null;
    }
}
