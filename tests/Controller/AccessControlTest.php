<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guards the deny-by-default access_control catch-all. The Live Component endpoint
 * carries no #[IsGranted] and matches no enumerated public prefix, so it is protected
 * solely by the trailing `^/ IS_AUTHENTICATED_FULLY` rule. If access_control is ever
 * widened (e.g. a stray `^/ PUBLIC_ACCESS` above the catch-all), this test fails.
 */
final class AccessControlTest extends WebTestCase
{
    public function testCatchAllRejectsAnonymousOnUnprefixedRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_components/Step3ClaimLiveComponent');

        $this->assertResponseRedirects();
        self::assertStringContainsString(
            '/login',
            (string) $client->getResponse()->headers->get('Location'),
            'An unprefixed, IsGranted-less route must fall through to the deny-by-default rule.',
        );
    }

    public function testEnumeratedPublicRouteStaysAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
    }
}
