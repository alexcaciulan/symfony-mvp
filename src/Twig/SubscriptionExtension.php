<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Billing\SubscriptionService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the current user's usable subscription to templates (the sidebar
 * plan badge). Lazy: only queries when the function is actually called.
 */
final class SubscriptionExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_subscription', $this->currentSubscription(...)),
        ];
    }

    public function currentSubscription(): ?Subscription
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->subscriptionService->getCurrentSubscription($user);
    }
}
