<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LoginRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Guards the per-username login throttle (audit #16) against case-variation bypass: the email
 * lookup is case-insensitive, so the throttle buckets must be too.
 */
final class LoginRateLimiterTest extends TestCase
{
    public function testUsernameBucketIsCaseInsensitive(): void
    {
        $usernameFactory = new RateLimiterFactory(
            ['id' => 'login_username', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
        $roomy = static fn (string $id): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => 1000, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );

        $limiter = new LoginRateLimiter($roomy('login_global'), $roomy('login_local'), $usernameFactory);

        // Distinct IPs isolate the username tier as the only bucket the three attempts can share.
        $limiter->consume($this->loginRequest('User@Test.com', '10.0.0.1'));
        $limiter->consume($this->loginRequest('user@test.com', '10.0.0.2'));
        $third = $limiter->consume($this->loginRequest('USER@TEST.COM', '10.0.0.3'));

        self::assertFalse($third->isAccepted(), 'Case variants of one username must share a throttle bucket.');
    }

    private function loginRequest(string $username, string $ip): Request
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return $request;
    }
}
