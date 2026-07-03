<?php

declare(strict_types=1);

namespace App\DTO\Billing\Netopia;

/**
 * Verified, decoded content of a Netopia IPN (Instant Payment Notification).
 * Produced by {@see App\Service\Billing\Netopia\NetopiaApiClient::verifyIpn()}
 * only AFTER the JWT signature has been validated against the POS public key.
 *
 * `token`/`tokenExpiresAt`/`cardMask` are present whenever the transaction
 * carries a saved-card binding: on the first recurring payment AND on every
 * subsequent token charge, which rotates the token (the new value must overwrite
 * the stored one). Absent for one-off payments.
 */
final readonly class NetopiaIpnResult
{
    public function __construct(
        public string $orderId,
        public int $status,
        public ?string $ntpID,
        public bool $paid,
        public ?string $token = null,
        public ?string $tokenExpiresAt = null,
        public ?string $cardMask = null,
    ) {}
}
