<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use App\Service\Billing\Netopia\NetopiaApiClient;
use App\Service\Billing\Netopia\NetopiaException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reconciles payments whose IPN was lost: for each stale-pending invoice that
 * carries a gateway transaction id, re-queries the live status and settles it
 * if the gateway reports it paid. Idempotent (re-checks PENDING before settling)
 * and safe to run repeatedly; it is the backstop behind the webhook.
 */
final class PaymentReconciliationService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly NetopiaApiClient $client,
        private readonly InvoicingService $invoicing,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return array{checked: int, settled: int, still_pending: int, errors: int}
     */
    public function reconcile(\DateTimeImmutable $before): array
    {
        $summary = ['checked' => 0, 'settled' => 0, 'still_pending' => 0, 'errors' => 0];

        foreach ($this->invoices->findStalePending($before) as $invoice) {
            ++$summary['checked'];

            $ntpID = $invoice->getExternalId();
            if (null === $ntpID) {
                continue; // guarded by the query, but keep the type check explicit
            }

            try {
                $status = $this->client->fetchStatus($ntpID);
            } catch (NetopiaException $e) {
                $this->logger->error('reconcile.status_error', [
                    'invoiceId' => $invoice->getId(),
                    'reason' => $e->getMessage(),
                ]);
                ++$summary['errors'];
                continue;
            }

            if (!$this->client->isPaidStatus($status)) {
                ++$summary['still_pending'];
                continue;
            }

            // markPaid is atomic (settles PENDING → PAID once, even against a
            // concurrent IPN); the local pre-check just avoids a needless call.
            if (InvoiceStatus::PENDING === $invoice->getStatus()) {
                $this->invoicing->markPaid($invoice, $ntpID, 'reconciliation');
                ++$summary['settled'];
            }
        }

        return $summary;
    }
}
