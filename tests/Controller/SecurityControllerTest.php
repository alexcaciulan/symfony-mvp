<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityControllerTest extends WebTestCase
{
    private function createTestUser(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): User
    {
        $existing = $em->getRepository(User::class)->findOneBy(['email' => 'security-test@example.com']);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
        }

        $user = new User();
        $user->setEmail('security-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $user->setIsVerified(true);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testLoginPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testLoginWithValidCredentials(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $this->createTestUser($em, $hasher);

        $crawler = $client->request('GET', '/login');
        $form = $crawler->filter('form button[type="submit"]')->form([
            '_username' => 'security-test@example.com',
            '_password' => 'password123',
        ]);

        $client->submit($form);
        $this->assertResponseRedirects();

        // Clean up
        $em->getConnection()->executeStatement("DELETE FROM user WHERE email = 'security-test@example.com'");
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $this->createTestUser($em, $hasher);

        $crawler = $client->request('GET', '/login');
        $form = $crawler->filter('form button[type="submit"]')->form([
            '_username' => 'security-test@example.com',
            '_password' => 'wrongpassword',
        ]);

        $client->submit($form);
        $this->assertResponseRedirects('/login');

        // Follow redirect to see error
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        // Clean up
        $em->getConnection()->executeStatement("DELETE FROM user WHERE email = 'security-test@example.com'");
    }

    public function testLogoutRedirects(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher);
        $client->loginUser($user);

        // Logout is now CSRF-protected: submit the form (which carries the token).
        $crawler = $client->request('GET', '/');
        $client->submit($crawler->filter('form[action="/logout"] button')->form());
        $this->assertResponseRedirects();

        // Clean up
        $em->getConnection()->executeStatement("DELETE FROM user WHERE email = 'security-test@example.com'");
    }

    public function testLogoutViaGetIsRejected(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher);
        $client->loginUser($user);

        // A plain GET (e.g. <img src="/logout">) must not log the user out.
        $client->request('GET', '/logout');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $em->getConnection()->executeStatement("DELETE FROM user WHERE email = 'security-test@example.com'");
    }

    public function testLogoutRejectsForgedCrossOriginPost(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher);
        $client->loginUser($user);

        // The real CSRF attack shape: an attacker page auto-submits a POST to /logout with
        // a foreign Origin/Referer and no valid double-submit cookie. The same-origin CSRF
        // check must reject it.
        $client->request('POST', '/logout', [], [], [
            'HTTP_REFERER' => 'https://evil.example.com/',
            'HTTP_ORIGIN' => 'https://evil.example.com',
        ]);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $em->getConnection()->executeStatement("DELETE FROM user WHERE email = 'security-test@example.com'");
    }

    public function testProtectedPageRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/profile');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }
}
