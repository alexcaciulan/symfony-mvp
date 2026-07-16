<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Exercises the email-verification access gate: an authenticated-but-unverified user is
 * redirected to the check-email page on app routes, but keeps access to the onboarding
 * flow; a verified user is untouched.
 */
final class EmailVerificationGateTest extends WebTestCase
{
    private const PREFIX = 'verify-gate-';

    public function testUnverifiedUserIsRedirectedFromAppRoute(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser(false));

        $client->request('GET', '/dashboard');

        $this->assertResponseRedirects();
        self::assertStringContainsString(
            '/register/check-email',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    public function testUnverifiedUserCanReachCheckEmailWithoutLoop(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser(false));

        $client->request('GET', '/register/check-email');

        $this->assertResponseIsSuccessful();
    }

    public function testUnverifiedUserCanLogOut(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser(false));

        $client->request('GET', '/logout');

        // Logout is allowlisted: the gate must not intercept it with a check-email redirect.
        self::assertStringNotContainsString(
            '/register/check-email',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    public function testVerifiedUserReachesAppRoute(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser(true));

        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
    }

    private function persistUser(bool $verified): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail(self::PREFIX . uniqid() . '@test.com');
        $user->setPassword('x');
        $user->setIsVerified($verified);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    protected function tearDown(): void
    {
        if (static::getContainer()->has(EntityManagerInterface::class)) {
            static::getContainer()->get(EntityManagerInterface::class)
                ->getConnection()
                ->executeStatement('DELETE FROM user WHERE email LIKE ?', [self::PREFIX . '%']);
        }
        parent::tearDown();
    }
}
