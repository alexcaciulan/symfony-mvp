<?php

declare(strict_types=1);

namespace App\DTO\Calculation;

final readonly class PenaltyResult
{
    /** @param PenaltyPeriod[] $breakdown */
    public function __construct(
        public float $total,
        public array $breakdown,
    ) {}
}
