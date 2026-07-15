<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\NotificationType;

/**
 * Immutable per-event decision consumed by {@see NotificationDispatcher}: the
 * recipient, the in-app/email content, and which channels to fan out to.
 */
final readonly class NotificationDispatch
{
    /**
     * @param 'success'|'error'|'warning'|'info' $variant      Severity, carried on the in-app row and the client toast
     * @param array<string, mixed>               $emailContext Twig context for the email template
     * @param ?string                            $dedupKey     Stable idempotency key for retry-prone producers (Phase C)
     */
    public function __construct(
        public User $user,
        public ?LegalCase $legalCase,
        public NotificationType $type,
        public string $title,
        public string $message,
        public ?string $resourceLink,
        public string $variant,
        public ?string $emailSubject,
        public ?string $emailTemplate,
        public array $emailContext = [],
        public bool $persistInApp = true,
        public ?string $dedupKey = null,
    ) {}
}
