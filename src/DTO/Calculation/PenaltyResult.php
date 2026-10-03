<?php

declare(strict_types=1);

namespace App\DTO\Calculation;

final readonly class PenaltyResult
{
    /** @param PenaltyPeriod[] $breakdown */
    public function __construct(
        public float $total,
        public array $breakdown,
        public ?float $capAmount = null,
    ) {}

    /** Whether the contract's ceiling, rather than the daily rate, set the total. */
    public function isCapped(): bool
    {
        return $this->capAmount !== null;
    }
}
