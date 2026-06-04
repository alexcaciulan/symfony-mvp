<?php

namespace App\Tests\Controller;

use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegistrationControllerTest extends WebTestCase
{
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
            'registration_form[plainPassword][first]' => 'securepass123',
            'registration_form[plainPassword][second]' => 'securepass123',
        ]);

        $client->submit($form);
        $this->assertResponseRedirects('/register/check-email');

        // Verify user created in DB
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'new-user-reg@example.com']);
        $this->assertNotNull($user);
        $this->assertFalse($user->isVerified());

        // Verify password is hashed (not plaintext)
        $this->assertNotSame('securepass123', $user->getPassword());

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
            'registration_form[plainPassword][first]' => 'securepass123',
            'registration_form[plainPassword][second]' => 'securepass123',
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

    public function testRegisterWithDuplicateEmail(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $this->createTestUser($em, $hasher);

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => self::TEST_EMAIL,
            'registration_form[agreeTerms]' => true,
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);

        $client->submit($form);
        $this->assertResponseStatusCodeSame(422);

        $this->cleanupTestUsers($em);
    }

    public function testRegisterWithMismatchedPasswords(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => 'mismatch-test@example.com',
            'registration_form[agreeTerms]' => true,
            'registration_form[plainPassword][first]' => 'password123',
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
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
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
        $client->loginUser($user);

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
        $client->loginUser($user);

        $client->request('GET', '/register/resend-verification');
        $this->assertResponseRedirects('/register/check-email');

        // Follow redirect and check for "already verified" flash
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

    public function testVerifyEmailRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/verify/email');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }

    public function testVerifyEmailWithInvalidToken(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $client->getContainer()->get('security.user_password_hasher');

        $user = $this->createTestUser($em, $hasher, false);
        $client->loginUser($user);

        // Call verify/email with no valid signature params - should catch exception and redirect
        $client->request('GET', '/verify/email?expires=1&signature=invalid&token=bad');
        $this->assertResponseRedirects('/register');

        $this->cleanupTestUsers($em);
    }
}
