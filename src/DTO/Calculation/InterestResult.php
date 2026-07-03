<?php

namespace App\DTO\Calculation;

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
}
