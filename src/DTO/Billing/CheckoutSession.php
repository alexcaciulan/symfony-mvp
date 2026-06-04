<?php

declare(strict_types=1);

namespace App\DTO\Billing;

/**
 * A payment-gateway checkout handle: where to send the user to pay, and the
 * gateway's own reference for the session (null for the internal stub).
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $url,
        public ?string $externalId = null,
    ) {}
}
