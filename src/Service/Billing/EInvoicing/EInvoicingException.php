<?php

declare(strict_types=1);

namespace App\Service\Billing\EInvoicing;

/**
 * Raised by a provider adapter when issuing/cancelling/fetching fails.
 *
 * `retryable` distinguishes transient failures (network, 5xx, timeout) that a
 * Messenger retry can recover, from business failures (4xx, invalid CIF, missing
 * series) that will fail identically on every retry and must be parked.
 */
final class EInvoicingException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transient(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, retryable: true, previous: $previous);
    }

    public static function business(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, retryable: false, previous: $previous);
    }
}
