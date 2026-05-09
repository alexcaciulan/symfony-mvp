<?php

namespace App\DTO\Court;

use App\Entity\Court;

final readonly class CourtResolveResult
{
    /** @param list<Court> $alternatives */
    public function __construct(
        public ?Court $court,
        public array $alternatives,
        public string $explanationKey,
        public ClaimValueBreakdown $claimValue,
    ) {}
}
