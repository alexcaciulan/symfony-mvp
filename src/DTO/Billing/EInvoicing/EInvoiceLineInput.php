<?php

declare(strict_types=1);

namespace App\DTO\Billing\EInvoicing;

/**
 * One product/service line sent to the provider. The provider computes VAT and
 * totals from unitPriceNet * quantity at vatPercentage; vatName names the rate
 * (e.g. "Normala" or "SFDD" for exempt suppliers).
 */
final readonly class EInvoiceLineInput
{
    public function __construct(
        public string $name,
        public string $unitPriceNet,
        public string $quantity,
        public string $vatPercentage,
        public string $vatName = 'Normala',
    ) {}
}
