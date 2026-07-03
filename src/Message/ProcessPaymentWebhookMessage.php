<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message carrying an ALREADY-VERIFIED payment IPN. The webhook controller
 * validates the gateway signature synchronously (at the trust boundary) and
 * dispatches this with the normalized, trusted data; the handler settles the
 * invoice off the HTTP request so Netopia gets a fast ack and transient DB
 * failures are retried by Messenger.
 *
 * Idempotent by design: the handler only settles a still-PENDING invoice, so a
 * duplicate/replayed IPN (Netopia may resend) is a no-op.
 */
final readonly class ProcessPaymentWebhookMessage
{
    public function __construct(
        public int $invoiceId,
        public bool $paid,
        public ?string $externalRef,
        public int $status,
        public ?string $token = null,
        public ?string $tokenExpiresAt = null,
        public ?string $cardMask = null,
    ) {}
}
