<?php

namespace App\DTO\Calculation;

final readonly class InterestResult
{
    /** @param InterestPeriod[] $breakdown */
    public function __construct(
        public float $total,
        public array $breakdown,
    ) {}
}
