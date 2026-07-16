<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\CspNonceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CspNonceProviderTest extends TestCase
{
    public function testNonceIsStableWithinTheSameRequest(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());
        $provider = new CspNonceProvider($stack);

        self::assertSame($provider->getNonce(), $provider->getNonce());
    }

    public function testNonceDiffersAcrossRequests(): void
    {
        $stack = new RequestStack();
        $provider = new CspNonceProvider($stack);

        $stack->push(new Request());
        $first = $provider->getNonce();
        $stack->pop();

        $stack->push(new Request());
        $second = $provider->getNonce();

        self::assertNotSame($first, $second);
    }

    public function testNonceIsGeneratedWithoutAnActiveRequest(): void
    {
        $provider = new CspNonceProvider(new RequestStack());

        self::assertNotEmpty($provider->getNonce());
    }
}
