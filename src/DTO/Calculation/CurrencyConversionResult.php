<?php

declare(strict_types=1);

namespace App\DTO\Calculation;

/**
 * Result of converting a claim amount to RON at a BNR reference rate. For a
 * native-RON claim it is the identity conversion (`identity = true`, `rate = 1.0`,
 * `rateDate = null`).
 */
final readonly class CurrencyConversionResult
{
    public function __construct(
        public float $originalAmount,
        public string $originalCurrency,
        public float $ronAmount,
        public float $rate,
        public ?\DateTimeImmutable $rateDate,
        public bool $identity,
    ) {}
}
