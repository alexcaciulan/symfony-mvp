<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Service\Mercure\MercureTokenService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MercureExtension extends AbstractExtension
{
    public function __construct(
        private readonly MercureTokenService $tokenService,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $mercurePublicUrl,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mercure_topic_for_case', $this->topicForCase(...)),
            new TwigFunction('mercure_topic_for_case_extraction', $this->topicForCaseExtraction(...)),
            new TwigFunction('mercure_topic_for_user_notifications', $this->topicForUserNotifications(...)),
            new TwigFunction('mercure_token_for_user', $this->tokenForUser(...)),
            new TwigFunction('mercure_public_url', $this->publicUrl(...)),
        ];
    }

    public function publicUrl(): string
    {
        return $this->mercurePublicUrl;
    }

    public function topicForCase(LegalCase $case): string
    {
        return MercureTokenService::caseStatusTopic((int) $case->getId());
    }

    public function topicForCaseExtraction(LegalCase $case): string
    {
        return MercureTokenService::caseExtractionTopic((int) $case->getId());
    }

    public function topicForUserNotifications(User $user): string
    {
        return MercureTokenService::userNotificationTopic($user);
    }

    public function tokenForUser(User $user): string
    {
        return $this->tokenService->tokenForUser($user);
    }
}
