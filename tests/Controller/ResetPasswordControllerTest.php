<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResetPasswordControllerTest extends WebTestCase
{
    private function createTestUser(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): User
    {
        $existing = $em->getRepository(User::class)->findOneBy(['email' => 'reset-test@example.com']);
        if ($existing) {
            // Delete any reset password requests first (FK constraint)
            $em->getConnection()->executeStatement(
                'DELETE FROM reset_password_request WHERE user_id = ?',
                [$existing->getId()]
            );
            $em->remove($existing);
            $em->flush();
        }

        $user = new User();
        $user->setEmail('reset-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'oldpassword'));
        $user->setIsVerified(true);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testForgotPasswordPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
    }

    public function testForgotPasswordWithValidEmailRedirects(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $this->createTestUser($em, $hasher);

        $client->request('POST', '/forgot-password', [
            'email' => 'reset-test@example.com',
        ]);

        $this->assertResponseRedirects('/forgot-password/check-email');
    }

    public function testForgotPasswordWithInvalidEmailAlsoRedirects(): void
    {
        $client = static::createClient();
        $client->request('POST', '/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        // Should not reveal whether email exists
        $this->assertResponseRedirects('/forgot-password/check-email');
    }

    public function testCheckEmailPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password/check-email');

        $this->assertResponseIsSuccessful();
    }

    public function testResetPasswordWithoutTokenReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/reset-password');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLoginPageShowsForgotPasswordLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/forgot-password"]');
    }
}
