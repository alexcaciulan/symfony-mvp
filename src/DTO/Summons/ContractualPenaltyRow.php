<?php

declare(strict_types=1);

namespace App\DTO\Summons;

/**
 * One document in the contractual-penalty table. `dueDate` is shown as is;
 * `periodStart` is the first day of delay, the day after it.
 */
final readonly class ContractualPenaltyRow
{
    public function __construct(
        public ?string $documentNumber,
        public ?\DateTimeImmutable $documentDate,
        public float $balance,
        public \DateTimeImmutable $dueDate,
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
        public int $days,
        public float $dailyRate,
        public float $penalty,
    ) {}
}
