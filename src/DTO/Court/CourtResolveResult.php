<?php

namespace App\DTO\Court;

use App\Entity\Court;

final readonly class CourtResolveResult
{
    /**
     * @param list<Court>           $alternatives
     * @param array<string, string> $explanationParams translation params for `$explanationKey`
     *                                                 (several messages carry a `%county%` placeholder)
     */
    public function __construct(
        public ?Court $court,
        public array $alternatives,
        public string $explanationKey,
        public ClaimValueBreakdown $claimValue,
        public array $explanationParams = [],
    ) {}
}
