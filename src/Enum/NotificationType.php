<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Typed contract for the kind of notification. Stored on Notification.type as the
 * backing string; drives the center's icon, label, and tier (which decides whether
 * a client toast is raised). suppressible() gates Phase E preferences: a
 * non-suppressible type always reaches the user regardless of their mode.
 *
 * Only types with a live producer are declared here; new producers add their case
 * alongside their wiring.
 */
enum NotificationType: string
{
    case CASE_STATUS = 'case_status';
    case DEADLINE_ALERT = 'deadline_alert';
    case MISSING_COMMUNICATION_DATE = 'missing_communication_date';
    case PORTAL_EVENT = 'portal_event';
    case PORTAL_RULING_CONFIRMATION = 'portal_ruling_confirmation';
    case PAYMENT_FAILED = 'payment_failed';
    case TOKEN_EXPIRED = 'token_expired';
    case PAYMENT_SUCCEEDED = 'payment_succeeded';
    case INVOICE_ISSUED = 'invoice_issued';
    case SLOT_OVERAGE = 'slot_overage';

    public function label(): string
    {
        return 'enum.notification_type.' . $this->value;
    }

    /** Icon token resolved to an SVG by templates/notifications/_item.html.twig. */
    public function icon(): string
    {
        return match ($this) {
            self::CASE_STATUS => 'scale',
            self::DEADLINE_ALERT => 'clock',
            self::MISSING_COMMUNICATION_DATE => 'calendar',
            self::PORTAL_EVENT => 'globe',
            self::PORTAL_RULING_CONFIRMATION => 'globe',
            self::PAYMENT_FAILED => 'card',
            self::TOKEN_EXPIRED => 'card',
            self::PAYMENT_SUCCEEDED => 'card',
            self::INVOICE_ISSUED => 'document',
            self::SLOT_OVERAGE => 'card',
        };
    }

    /** high|medium|low. Only high/medium raise a client toast on poll. */
    public function tier(): string
    {
        return 'high';
    }

    /** When false, Phase E preferences can never mute this type on any channel. */
    public function suppressible(): bool
    {
        return false;
    }
}
