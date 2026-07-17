<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Login throttling with a per-username tier on top of Symfony's default IP + IP/username
 * tiers, so a botnet rotating IPs against one lawyer's account is still blocked (audit #16).
 */
final class LoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $loginGlobalLimiter,
        private readonly RateLimiterFactory $loginLocalLimiter,
        private readonly RateLimiterFactory $loginUsernameLimiter,
    ) {
    }

    /**
     * @return LimiterInterface[]
     */
    protected function getLimiters(Request $request): array
    {
        $username = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');
        // Normalize case so variants (User@x / USER@x) share a bucket, mirroring
        // DefaultLoginRateLimiter; the email lookup is case-insensitive anyway.
        $username = preg_match('//u', $username) ? mb_strtolower($username, 'UTF-8') : strtolower($username);
        $ip = (string) $request->getClientIp();

        return [
            $this->loginGlobalLimiter->create($ip),
            $this->loginLocalLimiter->create($ip . ':' . $username),
            $this->loginUsernameLimiter->create($username),
        ];
    }
}
