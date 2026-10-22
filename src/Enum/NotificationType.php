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
    case BLOCKED_CASE_ALERT = 'blocked_case_alert';
    case PORTAL_EVENT = 'portal_event';
    case PORTAL_RULING_CONFIRMATION = 'portal_ruling_confirmation';
    case PORTAL_QUERY_FAILED = 'portal_query_failed';
    case PORTAL_CASE_FOUND = 'portal_case_found';
    case PAYMENT_FAILED = 'payment_failed';
    case TOKEN_EXPIRED = 'token_expired';
    case PAYMENT_SUCCEEDED = 'payment_succeeded';
    case INVOICE_ISSUED = 'invoice_issued';
    case SLOT_OVERAGE = 'slot_overage';
    case FILING_SENT = 'filing_sent';
    case FILING_ANNEXES_CANCELLED = 'filing_annexes_cancelled';
    case FILING_FAILED_TEMPORARY = 'filing_failed_temporary';
    case FILING_FAILED_PERMANENT = 'filing_failed_permanent';
    case FILING_FAILED_SIZE = 'filing_failed_size';
    case FILING_FAILED_INTERNAL = 'filing_failed_internal';
    case FILING_NEEDS_REVIEW = 'filing_needs_review';
    case FILING_PARTIALLY_SENT = 'filing_partially_sent';

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
            self::BLOCKED_CASE_ALERT => 'calendar',
            self::PORTAL_EVENT => 'globe',
            self::PORTAL_RULING_CONFIRMATION => 'globe',
            self::PORTAL_QUERY_FAILED => 'globe',
            self::PORTAL_CASE_FOUND => 'globe',
            self::PAYMENT_FAILED => 'card',
            self::TOKEN_EXPIRED => 'card',
            self::PAYMENT_SUCCEEDED => 'card',
            self::INVOICE_ISSUED => 'document',
            self::SLOT_OVERAGE => 'card',
            self::FILING_SENT => 'document',
            self::FILING_ANNEXES_CANCELLED => 'document',
            self::FILING_FAILED_TEMPORARY => 'document',
            self::FILING_FAILED_PERMANENT => 'document',
            self::FILING_FAILED_SIZE => 'document',
            self::FILING_FAILED_INTERNAL => 'document',
            self::FILING_NEEDS_REVIEW => 'document',
            self::FILING_PARTIALLY_SENT => 'document',
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
