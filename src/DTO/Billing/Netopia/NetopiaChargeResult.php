<?php

declare(strict_types=1);

namespace App\DTO\Billing\Netopia;

/**
 * Result of an off-session recurring charge on a saved token
 * ({@see App\Service\Billing\Netopia\NetopiaApiClient::chargeToken()}).
 *
 * `accepted` means the gateway took the charge for processing (no user
 * interaction, no 3DS redirect). As with on-session payments, the definitive
 * paid/failed confirmation still arrives via IPN; `errorMessage` carries the
 * gateway's reason when the charge is rejected outright (e.g. expired card).
 */
final readonly class NetopiaChargeResult
{
    public function __construct(
        public bool $accepted,
        public int $status,
        public ?string $ntpID,
        public ?string $errorMessage = null,
    ) {}
}
