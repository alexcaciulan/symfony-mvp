<?php

declare(strict_types=1);

namespace App\DTO\Summons;

/**
 * One NBR-rate interval of one document in the legal-interest table.
 *
 * `periodStart` is already the first day that accrues (the day after the due
 * date or after the rate change), so the displayed interval holds exactly
 * `days` days. `interest` is the unrounded value; the template rounds it.
 */
final readonly class LegalInterestRow
{
    public function __construct(
        public ?string $documentNumber,
        public ?\DateTimeImmutable $documentDate,
        public float $balance,
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
        public int $days,
        public float $nbrRate,
        public float $applicableRate,
        public float $interest,
    ) {}
}
