<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end assertion that SecurityHeadersSubscriber emits the hardening headers and
 * that the CSP nonce in the header matches the nonce stamped on the page's inline
 * scripts (otherwise the app's own scripts would be blocked).
 */
final class SecurityHeadersTest extends WebTestCase
{
    public function testHardeningHeadersPresentOnHtmlResponse(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertNotNull($headers->get('Permissions-Policy'));

        $csp = (string) $headers->get('Content-Security-Policy');
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("base-uri 'self'", $csp);
        self::assertStringContainsString("'strict-dynamic'", $csp);
        self::assertMatchesRegularExpression("/script-src [^;]*'nonce-[^']+'/", $csp);
    }

    public function testCspNonceMatchesInlineScriptNonce(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $response = $client->getResponse();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/'nonce-([^']+)'/", $csp, $matches), 'CSP must carry a nonce');

        self::assertStringContainsString(
            'nonce="' . $matches[1] . '"',
            (string) $response->getContent(),
            'The CSP nonce must be stamped on the inline scripts so the app can run.',
        );
    }

    /**
     * The nonce CSP silently blocks inline event handlers (onclick=, onload=, ...) since a
     * nonce only covers <script> tags. Guards the authenticated shell (sidebar + topbar),
     * which is where such handlers historically slipped in.
     */
    public function testAuthenticatedShellCarriesNoInlineEventHandlers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('sec-headers-' . uniqid() . '@test.com');
        $user->setPassword('x');
        $user->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/notifications');
            self::assertResponseIsSuccessful();

            self::assertDoesNotMatchRegularExpression(
                '/\son(click|load|change|submit|input|error|focus|blur|key\w+|mouse\w+)\s*=/i',
                (string) $client->getResponse()->getContent(),
                'Inline event handlers are blocked by the nonce CSP; use Stimulus data-action instead.',
            );
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM user WHERE email LIKE ?', ['sec-headers-%']);
        }
    }

    public function testHstsIsOnlyEmittedOverHttps(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login');
        self::assertFalse(
            $client->getResponse()->headers->has('Strict-Transport-Security'),
            'HSTS must not be sent over plain HTTP.',
        );

        $client->request('GET', '/login', server: [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);
        self::assertTrue(
            $client->getResponse()->headers->has('Strict-Transport-Security'),
            'HSTS must be sent once the request is seen as HTTPS behind the trusted proxy.',
        );
    }
}
