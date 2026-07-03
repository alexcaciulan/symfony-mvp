<?php

declare(strict_types=1);

namespace App\DTO\Billing\EInvoicing;

use App\Enum\EInvoiceStatus;

/** Normalized e-Factura (SPV) status, fetched by polling the provider. */
final readonly class EInvoiceStatusResult
{
    public function __construct(
        public EInvoiceStatus $eInvoiceStatus,
        public ?string $spvId = null,
        public ?string $error = null,
    ) {}
}
