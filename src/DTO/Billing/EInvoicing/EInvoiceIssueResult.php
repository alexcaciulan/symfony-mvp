<?php

declare(strict_types=1);

namespace App\DTO\Billing\EInvoicing;

use App\Enum\EInvoiceStatus;

/**
 * Normalized result of issuing an invoice. `series`/`number` are allocated by
 * the provider. PDF arrives either as a URL (Oblio `link`) or as raw bytes
 * (SmartBill), never both relevant at once.
 */
final readonly class EInvoiceIssueResult
{
    public function __construct(
        public string $providerName,
        public string $series,
        public string $number,
        public ?string $providerInvoiceId = null,
        public EInvoiceStatus $eInvoiceStatus = EInvoiceStatus::NOT_APPLICABLE,
        public ?string $pdfUrl = null,
        public ?string $pdfBytes = null,
        public ?string $spvId = null,
    ) {}
}
