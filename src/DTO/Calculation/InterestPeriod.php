<?php

namespace App\DTO\Calculation;

final readonly class InterestPeriod
{
    public function __construct(
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public float $nbrRate,
        public float $applicableRate,
        public int $days,
        public float $periodInterest,
    ) {}
}
