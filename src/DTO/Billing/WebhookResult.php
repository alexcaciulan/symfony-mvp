<?php

declare(strict_types=1);

namespace App\DTO\Billing;

/**
 * Normalized result of a payment-gateway webhook: which invoice it concerns,
 * whether it was paid, and the gateway's external reference. Unused by the
 * stub (no real webhooks). The optional `token*`/`cardMask` fields carry a
 * saved recurring instrument when the processor returns one on the first paid
 * transaction, so subscriptions can later be charged off-session.
 */
final readonly class WebhookResult
{
    public function __construct(
        public ?int $invoiceId,
        public bool $paid,
        public ?string $externalRef = null,
        public int $status = 0,
        public ?string $token = null,
        public ?string $tokenExpiresAt = null,
        public ?string $cardMask = null,
    ) {}
}
