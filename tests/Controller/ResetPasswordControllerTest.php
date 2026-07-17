<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

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
        $this->assertSelectorExists('input[name="reset_password_request_form[email]"]');
    }

    public function testForgotPasswordWithValidEmailRedirects(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $this->createTestUser($em, $hasher);

        $crawler = $client->request('GET', '/forgot-password');
        $client->submit($crawler->filter('form button[type="submit"]')->form([
            'reset_password_request_form[email]' => 'reset-test@example.com',
        ]));

        $this->assertResponseRedirects('/forgot-password/check-email');
    }

    public function testForgotPasswordWithInvalidEmailAlsoRedirects(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/forgot-password');
        $client->submit($crawler->filter('form button[type="submit"]')->form([
            'reset_password_request_form[email]' => 'nonexistent@example.com',
        ]));

        // Should not reveal whether email exists
        $this->assertResponseRedirects('/forgot-password/check-email');
    }

    public function testResetPasswordWithValidTokenChangesPassword(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');
        $resetHelper = $client->getContainer()->get(ResetPasswordHelperInterface::class);

        $user = $this->createTestUser($em, $hasher);
        $token = $resetHelper->generateResetToken($user);

        // The tokened URL stores the token in session and redirects to the form.
        $client->request('GET', '/reset-password/' . $token->getToken());
        $crawler = $client->followRedirect();

        // The FormType submission carries CSRF automatically.
        $client->submit($crawler->filter('form button[type="submit"]')->form([
            'reset_password_form[plainPassword][first]' => 'Zx9mKp2Lq7Rw',
            'reset_password_form[plainPassword][second]' => 'Zx9mKp2Lq7Rw',
        ]));
        $this->assertResponseRedirects('/login');

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'reset-test@example.com']);
        self::assertTrue($hasher->isPasswordValid($reloaded, 'Zx9mKp2Lq7Rw'), 'The reset must set the new password.');
    }

    public function testResetPasswordRejectsShortPassword(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');
        $resetHelper = $client->getContainer()->get(ResetPasswordHelperInterface::class);

        $user = $this->createTestUser($em, $hasher);
        $token = $resetHelper->generateResetToken($user);

        $client->request('GET', '/reset-password/' . $token->getToken());
        $crawler = $client->followRedirect();

        // Below the 12-char floor: the form is invalid, so no redirect to login.
        $client->submit($crawler->filter('form button[type="submit"]')->form([
            'reset_password_form[plainPassword][first]' => 'short',
            'reset_password_form[plainPassword][second]' => 'short',
        ]));
        self::assertFalse($client->getResponse()->isRedirect('/login'));

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'reset-test@example.com']);
        self::assertTrue($hasher->isPasswordValid($reloaded, 'oldpassword'), 'A rejected reset must leave the old password intact.');
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
