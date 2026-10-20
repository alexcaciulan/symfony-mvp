<?php

namespace App\DTO\Calculation;

use App\Enum\ProfessionalPenaltyMargin;

final readonly class InterestResult
{
    /**
     * Echoes the calculation inputs so a printed breakdown is self-contained
     * and reproducible (audit trail). `invoiceDate` documents the source
     * invoice only; it does not affect the computed interest.
     *
     * @param InterestPeriod[] $breakdown
     */
    public function __construct(
        public float $total,
        public array $breakdown,
        public \DateTimeImmutable $dueDate,
        public \DateTimeImmutable $referenceDate,
        public ?\DateTimeImmutable $invoiceDate = null,
    ) {}

    /**
     * The margin applied over the NBR rate. One per result: it follows the
     * contract, so every period of a claim carries the same one.
     */
    public function margin(): ?ProfessionalPenaltyMargin
    {
        return ($this->breakdown[0] ?? null)?->margin;
    }
}
