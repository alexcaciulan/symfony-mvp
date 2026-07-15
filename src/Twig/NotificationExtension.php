<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\Notification\NotificationCenterService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the current user's unread notification count so the topbar bell renders
 * an accurate badge on first paint, before the client poller takes over.
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationCenterService $center,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', $this->unreadCount(...)),
            new TwigFunction('notification_type', NotificationType::tryFrom(...)),
        ];
    }

    public function unreadCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->center->unreadCount($user);
    }
}
