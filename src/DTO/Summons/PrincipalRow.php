<?php

declare(strict_types=1);

namespace App\DTO\Summons;

/**
 * One outstanding document in the payment notice's principal table. `amount`
 * is the signed RON value, so a credit note reads negative.
 */
final readonly class PrincipalRow
{
    public function __construct(
        public ?string $documentNumber,
        public ?\DateTimeImmutable $documentDate,
        public ?\DateTimeImmutable $dueDate,
        public float $amount,
        public ?float $originalAmount = null,
        public ?string $originalCurrency = null,
        public ?float $exchangeRate = null,
        public ?\DateTimeImmutable $exchangeRateDate = null,
    ) {}
}
