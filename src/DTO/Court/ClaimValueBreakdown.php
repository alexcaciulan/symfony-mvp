<?php

namespace App\DTO\Court;

final readonly class ClaimValueBreakdown
{
    public function __construct(
        public float $principal,
        public float $accruedInterest,
        public float $scadentPenalties,
        public float $total,
    ) {}
}
