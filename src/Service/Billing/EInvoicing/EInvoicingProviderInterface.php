<?php

declare(strict_types=1);

namespace App\Service\Billing\EInvoicing;

use App\DTO\Billing\EInvoicing\EInvoiceCancelResult;
use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceIssueResult;
use App\DTO\Billing\EInvoicing\EInvoiceStatusResult;
use App\Entity\FiscalInvoice;

/**
 * Adapter to an external accounting/e-invoicing provider (Oblio, SmartBill) or
 * the local stub. The provider owns numbering, VAT, the official PDF and the
 * e-Factura (SPV) transmission; the app only sends a normalized request and
 * mirrors the result.
 *
 * Implementations are tagged `app.einvoicing_provider` and selected at runtime
 * by {@see EInvoicingProviderResolver} based on an admin-editable setting.
 */
interface EInvoicingProviderInterface
{
    /** Stable identifier used by the resolver/toggle: 'stub' | 'oblio' | 'smartbill'. */
    public function getName(): string;

    /** Issue an invoice (create + mark collected + send to SPV where applicable). */
    public function issue(EInvoiceIssueRequest $request): EInvoiceIssueResult;

    /** Poll the current e-Factura (SPV) status for an already issued invoice. */
    public function fetchEInvoiceStatus(FiscalInvoice $invoice): EInvoiceStatusResult;

    /** Issue a storno (correction invoice) reversing the given invoice. */
    public function storno(FiscalInvoice $invoice, string $reason): EInvoiceCancelResult;

    /** Fetch the official PDF bytes for an issued invoice, or null if unavailable. */
    public function getPdf(FiscalInvoice $invoice): ?string;

    /** Whether this provider/account has e-Factura (SPV) transmission enabled. */
    public function isEFacturaEnabled(): bool;
}
