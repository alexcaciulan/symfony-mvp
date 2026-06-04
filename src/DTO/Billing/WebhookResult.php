<?php

declare(strict_types=1);

namespace App\DTO\Billing;

/**
 * Normalized result of a payment-gateway webhook: which invoice it concerns,
 * whether it was paid, and the gateway's external reference. Unused by the
 * stub (no real webhooks); shape ready for a real processor post-MVP.
 */
final readonly class WebhookResult
{
    public function __construct(
        public ?int $invoiceId,
        public bool $paid,
        public ?string $externalRef = null,
    ) {}
}
