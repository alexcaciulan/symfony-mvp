<?php

declare(strict_types=1);

namespace App\DTO\Billing;

/**
 * Aggregated VAT totals for an invoice. All values are DECIMAL strings with 2
 * decimals (computed in integer bani by {@see App\Service\Billing\VatCalculator}).
 *
 * @phpstan-type RateBucket array{rate: string, net: string, vat: string, gross: string}
 */
final readonly class VatBreakdown
{
    /**
     * @param RateBucket[] $byRate per-rate buckets (a single 19% bucket in MVP)
     */
    public function __construct(
        public string $netTotal,
        public string $vatTotal,
        public string $grossTotal,
        public array $byRate = [],
    ) {}
}
