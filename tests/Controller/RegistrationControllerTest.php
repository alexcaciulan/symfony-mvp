<?php

namespace App\Tests\Controller;

use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class RegistrationControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const TEST_EMAIL = 'register-test@example.com';

    private function createTestUser(EntityManagerInterface $em, UserPasswordHasherInterface $hasher, bool $verified = true): User
    {
        $existing = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
        }

        $user = new User();
        $user->setEmail(self::TEST_EMAIL);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $user->setIsVerified($verified);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Seeds the `registration_pending_email` session marker that the (now anonymous)
     * check-email / resend flow keys on, mirroring what register() sets.
     */
    private function setPendingEmail(KernelBrowser $client, string $email): void
    {
        $session = $client->getContainer()->get('session.factory')->createSession();
        $session->set('registration_pending_email', $email);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function cleanupTestUsers(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        // Registration now starts a trial subscription, so delete billing rows
        // (FK to user) before the users themselves.
        $like = "(u.email LIKE 'register-test%' OR u.email LIKE 'new-user%')";
        $conn->executeStatement("DELETE i FROM invoice i JOIN `user` u ON i.user_id = u.id WHERE $like");
        $conn->executeStatement("DELETE s FROM subscription s JOIN `user` u ON s.user_id = u.id WHERE $like");
        $conn->executeStatement("DELETE a FROM audit_log a JOIN `user` u ON a.user_id = u.id WHERE $like");
        $conn->executeStatement("DELETE FROM `user` WHERE email LIKE 'register-test%' OR email LIKE 'new-user%'");
    }

    public function testRegisterPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testRegisterWithValidData(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        // Cleanup any previous test user (+ its billing rows, FK first).
        $this->cleanupTestUsers($em);

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => 'new-user-reg@example.com',
            'registration_form[agreeTerms]' => true,
            'registration_form[plainPassword][first]' => 'Zx9mKp2Lq7Rw',
            'registration_form[plainPassword][second]' => 'Zx9mKp2Lq7Rw',
        ]);

        $client->submit($form);
        $this->assertResponseRedirects('/register/check-email');

        // Verify user created in DB
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'new-user-reg@example.com']);
        $this->assertNotNull($user);
        $this->assertFalse($user->isVerified());

        // Verify password is hashed (not plaintext)
        $this->assertNotSame('Zx9mKp2Lq7Rw', $user->getPassword());

        // Cleanup (registration may have started a trial subscription → FK rows first).
        $this->cleanupTestUsers($em);
    }

    public function testRegisterStartsTrialSubscription(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $trialPlan = new Plan();
        $trialPlan->setName('reg-trial-plan-' . uniqid());
        $trialPlan->setPriceMonthly('0.00');
        $trialPlan->setIncludedCases(2);
        $trialPlan->setPricePerExtra('0.00');
        $trialPlan->setIsActive(true);
        $trialPlan->setIsTrial(true);
        $em->persist($trialPlan);
        $em->flush();

        $email = 'new-user-trial-' . uniqid() . '@example.com';
        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => $email,
            'registration_form[agreeTerms]' => true,
            'registration_form[plainPassword][first]' => 'Zx9mKp2Lq7Rw',
            'registration_form[plainPassword][second]' => 'Zx9mKp2Lq7Rw',
        ]);
        $client->submit($form);
        $this->assertResponseRedirects('/register/check-email');

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        $sub = $em->getRepository(Subscription::class)->findOneBy(['user' => $user->getId()]);
        $this->assertNotNull($sub, 'A trial subscription must be created on registration.');
        $this->assertSame(SubscriptionStatus::TRIAL, $sub->getStatus());

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE s FROM subscription s JOIN `user` u ON s.user_id = u.id WHERE u.email = ?', [$email]);
        $conn->executeStatement('DELETE a FROM audit_log a JOIN `user` u ON a.user_id = u.id WHERE u.email = ?', [$email]);
        $conn->executeStatement('DELETE FROM `user` WHERE email = ?', [$email]);
        $conn->executeStatement('DELETE FROM plan WHERE id = ?', [$trialPlan->getId()]);
    }

    public function testRegisterWithDuplicateEmailIsEnumerationSafe(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $this->createTestUser($em, $hasher);

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => self::TEST_EMAIL,
            'registration_form[agreeTerms]' => true,
            'registration_form[plainPassword][first]' => 'Zx9mKp2Lq7Rw',
            'registration_form[plainPassword][second]' => 'Zx9mKp2Lq7Rw',
        ]);
        $client->submit($form);

        // Same outcome as a fresh registration (no 422 "email exists" leak).
        $this->assertResponseRedirects('/register/check-email');
        // A notice was sent to the real owner, and no duplicate account was created.
        $this->assertEmailCount(1);
        self::assertSame(1, $em->getRepository(User::class)->count(['email' => self::TEST_EMAIL]));

        // The check-email page shows the submitted address exactly like the new-user path,
        // and the existing account was never logged in (no auto-login oracle).
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString(self::TEST_EMAIL, (string) $client->getResponse()->getContent());

        $client->request('GET', '/dashboard');
        $this->assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $this->cleanupTestUsers($em);
    }

    public function testInvalidFormReturnsSameStatusForExistingAndNewEmail(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $this->createTestUser($em, $hasher);

        // Same invalid payload (mismatched passwords) for an existing vs a brand-new email:
        // the status must be identical, or it leaks whether the address is registered.
        $submit = static function (string $email) use ($client): int {
            $crawler = $client->request('GET', '/register');
            $form = $crawler->filter('form button[type="submit"]')->form([
                'registration_form[email]' => $email,
                'registration_form[agreeTerms]' => true,
                'registration_form[plainPassword][first]' => 'Zx9mKp2Lq7Rw',
                'registration_form[plainPassword][second]' => 'does-not-match',
            ]);
            $client->submit($form);

            return $client->getResponse()->getStatusCode();
        };

        $existingStatus = $submit(self::TEST_EMAIL);
        $newStatus = $submit('brand-new-' . uniqid() . '@example.com');

        self::assertSame(422, $existingStatus, 'An invalid form must render errors, not a fake success, even for an existing email.');
        self::assertSame($existingStatus, $newStatus, 'Response status must not reveal whether the email is registered.');

        $this->cleanupTestUsers($em);
    }

    public function testRegisterWithMismatchedPasswords(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => 'mismatch-test@example.com',
            'registration_form[agreeTerms]' => true,
            'registration_form[plainPassword][first]' => 'Zx9mKp2Lq7Rw',
            'registration_form[plainPassword][second]' => 'differentpass',
        ]);

        $client->submit($form);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithShortPassword(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => 'short-pass@example.com',
            'registration_form[agreeTerms]' => true,
            'registration_form[plainPassword][first]' => 'abc',
            'registration_form[plainPassword][second]' => 'abc',
        ]);

        $client->submit($form);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithoutAgreeingTerms(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => 'no-terms@example.com',
            'registration_form[plainPassword][first]' => 'Zx9mKp2Lq7Rw',
            'registration_form[plainPassword][second]' => 'Zx9mKp2Lq7Rw',
        ]);
        // Do NOT check agreeTerms

        $client->submit($form);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCheckEmailPageForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher);
        $client->loginUser($user);

        $client->request('GET', '/register/check-email');
        $this->assertResponseIsSuccessful();

        $this->cleanupTestUsers($em);
    }

    public function testCheckEmailRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register/check-email');

        $this->assertResponseRedirects('/register');
    }

    public function testResendVerificationForUnverifiedUser(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher, false);
        $this->setPendingEmail($client, $user->getEmail());

        $client->request('GET', '/register/resend-verification');
        $this->assertResponseRedirects('/register/check-email');

        $this->cleanupTestUsers($em);
    }

    public function testResendVerificationForVerifiedUser(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher, true);
        $this->setPendingEmail($client, $user->getEmail());

        // A verified address gets the same generic outcome (no leak that it exists).
        $client->request('GET', '/register/resend-verification');
        $this->assertResponseRedirects('/register/check-email');

        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $this->cleanupTestUsers($em);
    }

    public function testResendVerificationRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register/resend-verification');

        $this->assertResponseRedirects('/register');
    }

    public function testVerifyEmailSucceedsAnonymouslyViaSignedUrl(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');
        $helper = $client->getContainer()->get(VerifyEmailHelperInterface::class);

        $user = $this->createTestUser($em, $hasher, false);
        self::assertFalse($user->isVerified());

        $signature = $helper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => (string) $user->getId()],
        );

        // No login: the signed URL alone must verify the account.
        $client->request('GET', $signature->getSignedUrl());
        $this->assertResponseRedirects('/login');

        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded->isVerified(), 'The signed link must verify the account anonymously.');

        $this->cleanupTestUsers($em);
    }

    public function testVerifyEmailWithoutIdRedirectsToRegister(): void
    {
        $client = static::createClient();
        // No `id` in the URL: the anonymous verify flow has no user to validate against.
        $client->request('GET', '/verify/email');

        $this->assertResponseRedirects('/register');
    }

    public function testVerifyEmailWithInvalidSignature(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher, false);

        // Valid id but tampered signature: handleEmailConfirmation throws, redirect to register.
        $client->request('GET', '/verify/email?id=' . $user->getId() . '&expires=1&signature=invalid&token=bad');
        $this->assertResponseRedirects('/register');

        $this->cleanupTestUsers($em);
    }
}
