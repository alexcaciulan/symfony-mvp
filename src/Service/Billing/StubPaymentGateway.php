<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\CheckoutSession;
use App\DTO\Billing\WebhookResult;
use App\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * NON-production payment gateway. `startCheckout` points to an internal page
 * with a "mark as paid" button (no real money moves); `handleWebhook` is unused.
 * Swap for a real processor by binding PaymentGatewayInterface to it instead.
 */
final class StubPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function startCheckout(Invoice $invoice): CheckoutSession
    {
        $url = $this->urlGenerator->generate(
            'app_subscription_checkout',
            ['invoiceId' => $invoice->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return new CheckoutSession($url);
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        throw new \LogicException('StubPaymentGateway has no webhook; payments are confirmed manually.');
    }
}
