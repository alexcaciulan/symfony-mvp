<?php

declare(strict_types=1);

namespace App\DTO\Billing\Netopia;

/**
 * Result of a Netopia "start payment" call: where to send the browser for the
 * hosted 3D Secure page, plus the gateway's own transaction id (`ntpID`) and the
 * initial status code. The final paid/failed outcome always arrives later via IPN.
 */
final readonly class NetopiaStartResult
{
    public function __construct(
        public string $redirectUrl,
        public ?string $ntpID,
        public int $status,
    ) {}
}
