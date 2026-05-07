<?php

declare(strict_types=1);

namespace App\Service\Mercure;

use App\Entity\User;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MercureTokenService
{
    public function __construct(
        #[Autowire('%env(MERCURE_JWT_SECRET)%')]
        #[\SensitiveParameter]
        private readonly string $jwtSecret,
    ) {
    }

    public function tokenForUser(User $user, int $ttlSeconds = 3600): string
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($this->jwtSecret));
        $now = new \DateTimeImmutable();

        $token = $config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+'.$ttlSeconds.' seconds'))
            ->withClaim('mercure', [
                'subscribe' => $this->topicsForUser($user),
            ])
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    /**
     * @return string[]
     */
    public function topicsForUser(User $user): array
    {
        $id = (string) $user->getId();

        return [
            self::userNotificationTopic($user),
            'user/'.$id.'/deadline-alert',
            'case/{id}/status-change',
        ];
    }

    public static function userNotificationTopic(User $user): string
    {
        return 'user/'.$user->getId().'/notification';
    }

    public static function caseStatusTopic(int $caseId): string
    {
        return 'case/'.$caseId.'/status-change';
    }

    public static function caseExtractionTopic(int $caseId): string
    {
        return 'case/'.$caseId.'/extraction-status';
    }

    public static function casePortalEventTopic(int $caseId): string
    {
        return 'case/'.$caseId.'/portal-event';
    }
}
