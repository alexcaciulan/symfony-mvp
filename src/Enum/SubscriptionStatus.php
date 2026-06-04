<?php

declare(strict_types=1);

namespace App\Enum;

enum SubscriptionStatus: string
{
    case ACTIVE = 'active';
    case TRIAL = 'trial';
    case PAST_DUE = 'past_due';
    case SUSPENDED = 'suspended';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return 'enum.subscription_status.' . $this->value;
    }

    /**
     * Whether this status, combined with a valid (non-expired) billing period,
     * entitles the user to activate cases. CANCELED stays usable until the paid
     * period ends (the period date, not the status, terminates access). PAST_DUE
     * and SUSPENDED gate immediately (no grace period, per product decision).
     */
    public function isUsable(): bool
    {
        return match ($this) {
            self::ACTIVE, self::TRIAL, self::CANCELED => true,
            default => false,
        };
    }
}
