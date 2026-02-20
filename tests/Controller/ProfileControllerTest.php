<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProfileControllerTest extends WebTestCase
{
    private function createTestUser(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): User
    {
        // Remove existing test user if any
        $existing = $em->getRepository(User::class)->findOneBy(['email' => 'profile-test@example.com']);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
        }

        $user = new User();
        $user->setEmail('profile-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $user->setIsVerified(true);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testProfileRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/profile');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }

    public function testProfileShowsUserData(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher);
        $client->loginUser($user);

        $client->request('GET', '/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'profile-test@example.com');
    }

    public function testProfileEditSavesData(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form button[type="submit"]')->form([
            'profile_edit[firstName]' => 'Ion',
            'profile_edit[lastName]' => 'Popescu',
            'profile_edit[phone]' => '0721000000',
        ]);

        $client->submit($form);
        $this->assertResponseRedirects('/profile');

        $em->clear();
        $updated = $em->getRepository(User::class)->find($user->getId());
        $this->assertSame('Ion', $updated->getFirstName());
        $this->assertSame('Popescu', $updated->getLastName());
        $this->assertSame('0721000000', $updated->getPhone());
    }

    public function testChangePasswordWithCorrectCurrent(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile/change-password');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form button[type="submit"]')->form([
            'change_password[currentPassword]' => 'password123',
            'change_password[newPassword][first]' => 'newpass456',
            'change_password[newPassword][second]' => 'newpass456',
        ]);

        $client->submit($form);
        $this->assertResponseRedirects('/profile');
    }

    public function testChangePasswordWithWrongCurrent(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile/change-password');

        $form = $crawler->filter('form button[type="submit"]')->form([
            'change_password[currentPassword]' => 'wrongpassword',
            'change_password[newPassword][first]' => 'newpass456',
            'change_password[newPassword][second]' => 'newpass456',
        ]);

        $client->submit($form);
        $this->assertResponseRedirects('/profile/change-password');
    }

    public function testChangePasswordRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/profile/change-password');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }
}
