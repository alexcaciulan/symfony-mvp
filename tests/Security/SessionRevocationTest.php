<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the securityStamp lever (audit #8): bumping a user's stamp (e.g. a password change on
 * another device, or an explicit revoke) logs out that user's other sessions on their next request.
 */
final class SessionRevocationTest extends WebTestCase
{
    private const PREFIX = 'revoke-';

    public function testSecurityStampBumpLogsOutTheSession(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail(self::PREFIX . uniqid() . '@test.com');
        $user->setPassword('x');
        $user->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/dashboard');
            $this->assertResponseIsSuccessful();

            // Another device bumps the stamp (password unchanged): isolates the stamp lever.
            $user->regenerateSecurityStamp();
            $em->flush();

            // This session's next request must be deauthenticated.
            $client->request('GET', '/dashboard');
            $this->assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM user WHERE email LIKE ?', [self::PREFIX . '%']);
        }
    }
}
