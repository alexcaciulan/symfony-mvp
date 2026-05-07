<?php

declare(strict_types=1);

namespace App\Tests\Service\Mercure;

use App\Entity\User;
use App\Service\Mercure\MercureTokenService;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\TestCase;

final class MercureTokenServiceTest extends TestCase
{
    private const SECRET = 'test-secret-with-at-least-32-characters!';

    public function testTokenForUserContainsUserSpecificSubscribeTopics(): void
    {
        $user = $this->makeUser(42);
        $service = new MercureTokenService(self::SECRET);

        $tokenString = $service->tokenForUser($user);
        $token = $this->parseToken($tokenString);
        $mercure = $token->claims()->get('mercure');

        $this->assertIsArray($mercure);
        $this->assertArrayHasKey('subscribe', $mercure);
        $this->assertContains('user/42/notification', $mercure['subscribe']);
        $this->assertContains('user/42/deadline-alert', $mercure['subscribe']);
    }

    public function testTokenForUserHasExpClaim(): void
    {
        $user = $this->makeUser(7);
        $service = new MercureTokenService(self::SECRET);

        $tokenString = $service->tokenForUser($user, 1800);
        $token = $this->parseToken($tokenString);

        $exp = $token->claims()->get('exp');
        $this->assertInstanceOf(\DateTimeImmutable::class, $exp);
        $now = new \DateTimeImmutable();
        $this->assertGreaterThan($now->getTimestamp(), $exp->getTimestamp());
        $this->assertLessThanOrEqual($now->getTimestamp() + 1800 + 5, $exp->getTimestamp());
    }

    public function testTopicHelpersReturnExpectedShape(): void
    {
        $user = $this->makeUser(99);

        $this->assertSame('user/99/notification', MercureTokenService::userNotificationTopic($user));
        $this->assertSame('case/15/status-change', MercureTokenService::caseStatusTopic(15));
        $this->assertSame('case/15/extraction-status', MercureTokenService::caseExtractionTopic(15));
        $this->assertSame('case/15/portal-event', MercureTokenService::casePortalEventTopic(15));
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);
        return $user;
    }

    private function parseToken(string $tokenString)
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        return $config->parser()->parse($tokenString);
    }
}
