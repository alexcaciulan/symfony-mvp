<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where the transmission of a filing to the court stands. An SMTP acceptance is
 * never the filing itself: the filing date stays the lawyer's declaration.
 */
enum FilingDeliveryStatus: string
{
    case QUEUED = 'QUEUED';
    case SENDING = 'SENDING';
    /** The petition left; annexes are still on their way. */
    case PARTIALLY_SENT = 'PARTIALLY_SENT';
    case SENT = 'SENT';
    case FAILED_TEMPORARY_EXHAUSTED = 'FAILED_TEMPORARY_EXHAUSTED';
    case FAILED_PERMANENT = 'FAILED_PERMANENT';
    case FAILED_SIZE = 'FAILED_SIZE';
    case FAILED_INTERNAL = 'FAILED_INTERNAL';
    /** Stopped without sending further; the lawyer has to look at it. */
    case NEEDS_REVIEW = 'NEEDS_REVIEW';
    case CANCELLED = 'CANCELLED';
    /** A late permanent bounce on the petition after the SMTP acceptance. */
    case UNDELIVERED = 'UNDELIVERED';

    public function label(): string
    {
        return 'enum.filing_delivery_status.' . $this->value;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SENT, self::FAILED_TEMPORARY_EXHAUSTED, self::FAILED_PERMANENT, self::FAILED_SIZE,
            self::FAILED_INTERNAL, self::CANCELLED, self::UNDELIVERED => true,
            self::QUEUED, self::SENDING, self::PARTIALLY_SENT, self::NEEDS_REVIEW => false,
        };
    }

    /** Whether the submission keeps the case's single active-filing slot. */
    public function holdsLock(): bool
    {
        return match ($this) {
            self::QUEUED, self::SENDING, self::PARTIALLY_SENT, self::NEEDS_REVIEW => true,
            default => false,
        };
    }

    /** Still moving on its own: the status card refreshes itself. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::QUEUED, self::SENDING, self::PARTIALLY_SENT], true);
    }
}
