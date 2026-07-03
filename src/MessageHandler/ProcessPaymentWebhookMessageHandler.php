<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Invoice;
use App\Enum\InvoiceStatus;
use App\Message\ProcessPaymentWebhookMessage;
use App\Repository\InvoiceRepository;
use App\Service\Billing\InvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async handler for a verified payment IPN {@see ProcessPaymentWebhookMessage}.
 *
 *  - Captures the recurring card token from ANY IPN that carries one and saves
 *    it on the subscription (see {@see captureRecurringToken()}); Netopia sends
 *    the token exactly once, so this cannot wait for the "paid" IPN.
 *  - Settles the invoice via {@see InvoicingService::markPaid()} (which also
 *    fires the fiscal-invoice message).
 *  - Idempotent: only a still-PENDING invoice is settled. A duplicate/replayed
 *    IPN, or one arriving after reconciliation already settled the invoice, is a
 *    no-op, so no second fiscal invoice is ever issued.
 *  - Failure policy: exceptions propagate so Messenger retries (transient DB
 *    errors must not lose a real payment); the PENDING guard makes retries safe.
 */
#[AsMessageHandler]
final class ProcessPaymentWebhookMessageHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoicingService $invoicing,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(ProcessPaymentWebhookMessage $message): void
    {
        $invoice = $this->invoices->find($message->invoiceId);
        if (null === $invoice) {
            $this->logger->warning('payment.webhook.invoice_not_found', ['invoiceId' => $message->invoiceId]);

            return;
        }

        // Capture the recurring token FIRST, regardless of paid state. Netopia
        // sends the token exactly once, at (pre)approval, and NEVER resends it
        // (not even via a manual re-notify), so it must be persisted the moment
        // it arrives, even on a not-yet-paid IPN. Each token charge rotates the
        // token, so this also overwrites the previous one.
        $this->captureRecurringToken($invoice, $message);

        if (!$message->paid) {
            $this->logger->info('payment.webhook.not_paid', [
                'invoiceId' => $message->invoiceId,
                'status' => $message->status,
            ]);

            return;
        }

        if (InvoiceStatus::PENDING !== $invoice->getStatus()) {
            $this->logger->info('payment.webhook.already_settled', [
                'invoiceId' => $message->invoiceId,
                'status' => $invoice->getStatus()->value,
            ]);

            return;
        }

        $this->invoicing->markPaid($invoice, $message->externalRef, 'webhook');
    }

    private function captureRecurringToken(Invoice $invoice, ProcessPaymentWebhookMessage $message): void
    {
        if (null === $message->token || '' === $message->token) {
            return;
        }
        $subscription = $invoice->getSubscription();
        if (null === $subscription) {
            return;
        }

        $subscription->setRecurringToken($message->token);
        $subscription->setCardMask($message->cardMask);

        $expiry = null !== $message->tokenExpiresAt ? $this->parseTokenExpiry($message->tokenExpiresAt) : null;
        if (null !== $expiry) {
            $subscription->setRecurringTokenExpiresAt($expiry);
        }

        $this->em->flush();
        $this->logger->info('payment.webhook.token_saved', ['subscriptionId' => $subscription->getId()]);
    }

    /**
     * Netopia token expiry may arrive as `MM/YY`, `YYYY-MM-DD`, or an ISO
     * datetime. Best-effort parse; null (kept unset) when unrecognized rather
     * than guessing, since the {@see hasChargeableToken()} guard treats a null
     * expiry as "no known horizon" and still allows the charge.
     */
    private function parseTokenExpiry(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);

        foreach (['Y-m-d', 'm/y', 'm/Y', \DateTimeInterface::ATOM] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw);
            if (false !== $parsed) {
                // MM/YY expiries mean "valid through end of that month".
                return in_array($format, ['m/y', 'm/Y'], true)
                    ? $parsed->modify('last day of this month')->setTime(23, 59, 59)
                    : $parsed;
            }
        }

        // Do not log the raw card-expiry value (payment-instrument attribute);
        // record only its length for diagnostics.
        $this->logger->warning('payment.webhook.token_expiry_unparsed', ['length' => strlen($raw)]);

        return null;
    }
}
