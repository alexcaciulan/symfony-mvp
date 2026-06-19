<?php

declare(strict_types=1);

namespace App\DTO\Calculation;

final readonly class PenaltyPeriod
{
    public function __construct(
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public float $dailyRate,
        public int $days,
        public float $periodPenalty,
    ) {}
}
