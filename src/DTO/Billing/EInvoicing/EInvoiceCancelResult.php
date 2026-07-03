<?php

declare(strict_types=1);

namespace App\DTO\Billing\EInvoicing;

/**
 * Normalized result of a storno (correction invoice). When the provider issues
 * a real reversal document, its series/number are returned for the mirror.
 */
final readonly class EInvoiceCancelResult
{
    public function __construct(
        public bool $success,
        public ?string $stornoSeries = null,
        public ?string $stornoNumber = null,
        public ?string $error = null,
    ) {}
}
