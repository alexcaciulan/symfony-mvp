<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\CheckoutSession;
use App\DTO\Billing\WebhookResult;
use App\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payment processor abstraction. The MVP ships {@see StubPaymentGateway}; a real
 * processor (e.g. Netopia) implements the same contract post-MVP without
 * touching the controllers.
 */
interface PaymentGatewayInterface
{
    /** Begin a checkout for an invoice; returns where to send the user to pay. */
    public function startCheckout(Invoice $invoice): CheckoutSession;

    /** Verify and interpret a processor callback into a normalized result. */
    public function handleWebhook(Request $request): WebhookResult;
}
