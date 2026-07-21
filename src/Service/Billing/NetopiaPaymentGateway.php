<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\CheckoutSession;
use App\DTO\Billing\Netopia\NetopiaChargeResult;
use App\DTO\Billing\WebhookResult;
use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Service\Billing\Netopia\NetopiaApiClient;
use App\Service\Billing\Netopia\NetopiaException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Real payment gateway backed by Netopia Payments API v2. Implements the same
 * {@see PaymentGatewayInterface} as {@see StubPaymentGateway}, so controllers
 * are untouched; the resolver picks between them from the PAYMENT_GATEWAY env.
 *
 * On-session: `startCheckout` opens a hosted-page payment and redirects the
 * user to Netopia's 3DS page. Off-session: `chargeToken` renews a subscription
 * on a saved token. Both are confirmed only via the IPN, mapped by
 * `handleWebhook` into the neutral {@see WebhookResult}.
 *
 * Order IDs are `INV-{invoiceId}-{nonce}`: the invoice id is recoverable from
 * the IPN, while the nonce keeps each attempt unique (Netopia requires distinct
 * order IDs across retries of the same invoice).
 */
// Not final: test double in SubscriptionRenewalServiceTest.
class NetopiaPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly NetopiaApiClient $client,
    ) {}

    public function checkoutOrigins(): array
    {
        return $this->client->checkoutOrigins();
    }

    public function startCheckout(Invoice $invoice): CheckoutSession
    {
        $invoiceId = $invoice->getId();
        if (null === $invoiceId) {
            throw new NetopiaException('Cannot start checkout for an unpersisted invoice');
        }

        $orderId = self::buildOrderId($invoiceId);
        $result = $this->client->startPayment($invoice, $invoice->getUser(), $orderId);

        return new CheckoutSession($result->redirectUrl, $result->ntpID);
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $ipn = $this->client->verifyIpn($request);

        return new WebhookResult(
            invoiceId: self::parseInvoiceId($ipn->orderId),
            paid: $ipn->paid,
            externalRef: $ipn->ntpID,
            status: $ipn->status,
            token: $ipn->token,
            tokenExpiresAt: $ipn->tokenExpiresAt,
            cardMask: $ipn->cardMask,
        );
    }

    /**
     * Off-session recurring charge for a subscription renewal. Returns the raw
     * gateway result (accepted/declined + ntpID); the definitive settlement still
     * arrives via IPN, but the caller branches its dunning on `accepted`.
     */
    public function chargeRenewal(Subscription $subscription, Invoice $invoice): NetopiaChargeResult
    {
        $invoiceId = $invoice->getId();
        if (null === $invoiceId) {
            throw new NetopiaException('Cannot charge an unpersisted invoice');
        }

        $orderId = self::buildOrderId($invoiceId);

        return $this->client->chargeToken($subscription, $invoice, $orderId);
    }

    public static function buildOrderId(int $invoiceId): string
    {
        // Unique per attempt: invoice id (recoverable) + high-resolution nonce.
        return sprintf('INV-%d-%s', $invoiceId, str_replace('.', '', (string) microtime(true)));
    }

    public static function parseInvoiceId(string $orderId): ?int
    {
        if (1 === preg_match('/^INV-(\d+)-/', $orderId, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
