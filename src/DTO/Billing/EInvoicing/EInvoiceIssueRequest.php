<?php

declare(strict_types=1);

namespace App\DTO\Billing\EInvoicing;

use App\DTO\Billing\PartySnapshot;

/**
 * Normalized request to issue one invoice, provider-agnostic. Each adapter maps
 * it to its own payload (Oblio `POST /docs/invoice`, SmartBill `POST /invoice`).
 *
 * `idempotencyKey` (the internal Invoice id) lets the provider/adapter avoid
 * duplicate invoices on a retry. `collect` marks the invoice as already paid.
 */
final readonly class EInvoiceIssueRequest
{
    /**
     * @param EInvoiceLineInput[] $lines
     */
    public function __construct(
        public string $supplierCif,
        public string $seriesName,
        public PartySnapshot $client,
        public array $lines,
        public string $idempotencyKey,
        public string $currency = 'RON',
        public string $language = 'RO',
        public ?\DateTimeImmutable $issueDate = null,
        public ?\DateTimeImmutable $dueDate = null,
        public ?EInvoiceCollect $collect = null,
    ) {}
}
