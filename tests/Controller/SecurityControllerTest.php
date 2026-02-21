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

        $client->request('GET', '/logout');
        $this->assertResponseRedirects();

        // Clean up
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
