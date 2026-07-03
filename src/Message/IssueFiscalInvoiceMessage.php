<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message dispatched after a legacy {@see \App\Entity\Invoice} is paid so
 * the fiscal invoice is issued through the external provider in a background
 * worker rather than blocking the checkout request (provider round-trips, e.g.
 * Oblio OAuth + create + SPV send, take seconds).
 *
 * Carries only the Invoice id; the handler reloads it at consume time, after the
 * caller's transaction has committed.
 */
final readonly class IssueFiscalInvoiceMessage
{
    public function __construct(public int $invoiceId) {}
}
