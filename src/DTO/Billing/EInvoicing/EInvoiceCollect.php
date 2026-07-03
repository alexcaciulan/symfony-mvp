<?php

declare(strict_types=1);

namespace App\DTO\Billing\EInvoicing;

/**
 * Payment already taken via the gateway, attached so the provider issues the
 * invoice as collected (otherwise it shows unpaid in the accounting). `type`
 * uses the provider's collect vocabulary (e.g. Oblio "Card", "Ordin de plata").
 */
final readonly class EInvoiceCollect
{
    public function __construct(
        public string $type,
        public string $value,
        public \DateTimeImmutable $date,
    ) {}
}
