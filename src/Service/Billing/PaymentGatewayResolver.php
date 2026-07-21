<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\CheckoutSession;
use App\DTO\Billing\WebhookResult;
use App\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;

/**
 * Delegating gateway: the app injects this as {@see PaymentGatewayInterface};
 * it forwards to either the {@see StubPaymentGateway} (dev/test, manual
 * confirmation) or the {@see NetopiaPaymentGateway} based on the PAYMENT_GATEWAY
 * env toggle. Deploy-time choice (not admin-editable), so it is read once.
 *
 * Injects the two concrete gateways directly (not the interface) to avoid the
 * circular alias, and is itself NOT one of them.
 */
final class PaymentGatewayResolver implements PaymentGatewayInterface
{
    public function __construct(
        private readonly StubPaymentGateway $stub,
        private readonly NetopiaPaymentGateway $netopia,
        private readonly string $paymentGatewayDefault,
    ) {}

    public function startCheckout(Invoice $invoice): CheckoutSession
    {
        return $this->active()->startCheckout($invoice);
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        return $this->active()->handleWebhook($request);
    }

    public function checkoutOrigins(): array
    {
        return $this->active()->checkoutOrigins();
    }

    public function active(): PaymentGatewayInterface
    {
        return match ($this->paymentGatewayDefault) {
            'netopia' => $this->netopia,
            'stub' => $this->stub,
            default => throw new \RuntimeException(sprintf(
                'Unknown PAYMENT_GATEWAY "%s". Use "stub" or "netopia".',
                $this->paymentGatewayDefault,
            )),
        };
    }
}
