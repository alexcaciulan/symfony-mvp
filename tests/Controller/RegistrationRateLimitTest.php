<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Two-tier registration throttling: a generous per-POST budget bounds the cost of running full
 * form validation (notably the NotCompromisedPassword breach lookup), while the strict budget
 * counts only valid, completed submissions so a mistyped password does not lock a user out.
 */
final class RegistrationRateLimitTest extends WebTestCase
{
    private const PREFIX = 'reg-rl-';

    public function testInvalidSubmissionsAreCappedByThePerAttemptBudget(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->overrideLimiters(attemptLimit: 2, strictLimit: 100);

        // Two invalid submissions (password too short) re-render; the third is throttled by the
        // per-attempt budget even though none of them ever reaches account creation.
        self::assertSame(422, $this->submit($client, self::PREFIX . 'a@test.com', 'short'));
        self::assertSame(422, $this->submit($client, self::PREFIX . 'b@test.com', 'short'));
        self::assertSame(302, $this->submit($client, self::PREFIX . 'c@test.com', 'short'));
    }

    public function testMistypedPasswordDoesNotConsumeTheAccountCreationBudget(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->overrideLimiters(attemptLimit: 100, strictLimit: 1);

        try {
            // Several invalid submissions must not touch the strict (account-creation) budget...
            for ($i = 0; $i < 3; ++$i) {
                self::assertSame(422, $this->submit($client, self::PREFIX . 'x' . $i . '@test.com', 'short'));
            }

            // ...so a first valid submission still creates the account (strict budget intact).
            $email = self::PREFIX . uniqid() . '@test.com';
            self::assertSame(302, $this->submit($client, $email, 'Zx9mKp2Lq7Rw'));
            self::assertStringContainsString('/register/check-email', (string) $client->getResponse()->headers->get('Location'));
        } finally {
            // A valid registration starts a trial subscription, so delete billing rows (FK to
            // user) before the users themselves.
            $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $like = "u.email LIKE '" . self::PREFIX . "%'";
            $conn->executeStatement("DELETE i FROM invoice i JOIN `user` u ON i.user_id = u.id WHERE $like");
            $conn->executeStatement("DELETE s FROM subscription s JOIN `user` u ON s.user_id = u.id WHERE $like");
            $conn->executeStatement("DELETE a FROM audit_log a JOIN `user` u ON a.user_id = u.id WHERE $like");
            $conn->executeStatement('DELETE FROM `user` WHERE email LIKE ?', [self::PREFIX . '%']);
        }
    }

    private function overrideLimiters(int $attemptLimit, int $strictLimit): void
    {
        $container = static::getContainer();
        $container->set('limiter.registration_attempt', new RateLimiterFactory(
            ['id' => 'registration_attempt', 'policy' => 'fixed_window', 'limit' => $attemptLimit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        ));
        $container->set('limiter.registration', new RateLimiterFactory(
            ['id' => 'registration', 'policy' => 'fixed_window', 'limit' => $strictLimit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        ));
    }

    private function submit(KernelBrowser $client, string $email, string $password): int
    {
        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form button[type="submit"]')->form([
            'registration_form[email]' => $email,
            'registration_form[agreeTerms]' => true,
            'registration_form[plainPassword][first]' => $password,
            'registration_form[plainPassword][second]' => $password,
        ]);
        $client->submit($form);

        return $client->getResponse()->getStatusCode();
    }
}
