<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\LegalCase;
use App\Entity\User;

/**
 * Immutable per-event decision consumed by {@see NotificationDispatcher}: the
 * recipient, the in-app/email/toast content, and which channels to fan out to.
 */
final readonly class NotificationDispatch
{
    /**
     * @param 'success'|'error'|'warning'|'info' $variant      Mercure toast variant
     * @param array<string, mixed>               $emailContext Twig context for the email template
     */
    public function __construct(
        public User $user,
        public ?LegalCase $legalCase,
        public string $type,
        public string $title,
        public string $message,
        public ?string $resourceLink,
        public string $variant,
        public ?string $emailSubject,
        public ?string $emailTemplate,
        public array $emailContext = [],
        public bool $persistInApp = true,
    ) {}
}
