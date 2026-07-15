<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Twig\Environment;

/**
 * DB + Twig integration for the subscription dunning notification: proves the
 * D8 template path resolves and renders, and that the dispatcher's dedup guard
 * collapses a same-key re-attempt to a single persisted in-app row.
 */
class SubscriptionDunningNotificationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NotificationRepository $repo;
    private NotificationDispatcherInterface $dispatcher;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(NotificationRepository::class);
        $this->dispatcher = static::getContainer()->get(NotificationDispatcherInterface::class);
        $this->testPrefix = 'dunning-notif-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    /**
     * D8 regression: the dunning email routes to
     * templates/emails/subscription_action_required.html.twig. Render it through the
     * real Twig environment with the context the dispatcher supplies and assert it
     * produces output (the old singular 'email/' path did not exist and threw).
     */
    public function testDunningEmailTemplateRendersWithoutError(): void
    {
        $twig = static::getContainer()->get(Environment::class);

        $html = $twig->render('emails/subscription_action_required.html.twig', [
            'reason' => 'charge_failed',
            'subscriptionUrl' => 'https://app.test/subscription',
            'userName' => 'Cabinet Popescu',
        ]);

        self::assertNotSame('', trim($html));
        self::assertStringContainsString('https://app.test/subscription', $html);
    }

    /**
     * The token_expired reason variant must also resolve its per-reason copy key.
     */
    public function testDunningEmailTemplateRendersTokenExpiredReason(): void
    {
        $twig = static::getContainer()->get(Environment::class);

        $html = $twig->render('emails/subscription_action_required.html.twig', [
            'reason' => 'token_expired',
            'subscriptionUrl' => 'https://app.test/subscription',
            'userName' => 'Cabinet Popescu',
        ]);

        self::assertNotSame('', trim($html));
    }

    /**
     * Dedup guard: two dispatches under the same dedup key persist exactly one
     * in-app row (a cron re-attempt on the same day is a no-op).
     */
    public function testDuplicateDedupKeyPersistsOnlyOneRow(): void
    {
        $dedupKey = 'dunning:test:' . $this->testPrefix;

        $this->dispatcher->dispatch($this->dunningDispatch($dedupKey));
        $this->dispatcher->dispatch($this->dunningDispatch($dedupKey));

        self::assertSame(1, $this->repo->count(['user' => $this->user, 'dedupKey' => $dedupKey]));
    }

    private function dunningDispatch(string $dedupKey): NotificationDispatch
    {
        return new NotificationDispatch(
            user: $this->user,
            legalCase: null,
            type: NotificationType::PAYMENT_FAILED,
            title: 'Reînnoirea abonamentului nu a putut fi încasată',
            message: 'Actualizează metoda de plată și reia plata din contul tău.',
            resourceLink: '/subscription',
            variant: 'error',
            // No email subject: this test targets the in-app dedup path only.
            emailSubject: null,
            emailTemplate: null,
            dedupKey: $dedupKey,
        );
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?',
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
