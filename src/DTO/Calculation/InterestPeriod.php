<?php

namespace App\DTO\Calculation;

use App\Enum\ProfessionalPenaltyMargin;

final readonly class InterestPeriod
{
    /**
     * `margin` is the margin added over `nbrRate` for penalty interest, null
     * for remunerative interest, which carries none.
     */
    public function __construct(
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public float $nbrRate,
        public float $applicableRate,
        public int $days,
        public float $periodInterest,
        public ?ProfessionalPenaltyMargin $margin = null,
    ) {}
}
