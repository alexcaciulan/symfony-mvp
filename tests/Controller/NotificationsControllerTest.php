<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'notif-page-' . uniqid();
        $this->user = $this->makeUser($this->prefix);
        $this->client->loginUser($this->user);
    }

    public function testIndexRendersDataTable(): void
    {
        $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        // The full inbox is the Tabulator table (rows load remotely via AJAX).
        self::assertSelectorExists('[data-controller="tabulator"]');
    }

    public function testUnreadCountReturnsJson(): void
    {
        $this->persistNotification('A', null);
        $this->persistNotification('B', null);
        $this->em->flush();

        $this->client->request('GET', '/notifications/unread-count');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $data['count']);
    }

    public function testDropdownRenders(): void
    {
        $this->persistNotification('Din dropdown', '/case/9');
        $this->em->flush();

        $this->client->request('GET', '/notifications/dropdown');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#notification-dropdown', 'Din dropdown');
    }

    public function testMarkReadFlipsFlagAndRedirectsToResource(): void
    {
        $notif = $this->persistNotification('Citește-mă', '/case/42');
        $this->em->flush();

        // Grab the session-valid token the dropdown item link carries, then POST as
        // the background mark-read does (non-XHR still redirects to the resource).
        $crawler = $this->client->request('GET', '/notifications/dropdown');
        $token = $crawler->filter('[data-notification-link-token-value]')->first()->attr('data-notification-link-token-value');
        $this->client->request('POST', '/notifications/' . $notif->getId() . '/read', ['_token' => $token]);

        self::assertResponseRedirects('/case/42');
        $this->em->clear();
        $reloaded = $this->em->getRepository(Notification::class)->find($notif->getId());
        self::assertTrue($reloaded->isRead());
        self::assertNotNull($reloaded->getReadAt());
    }

    public function testMarkReadRejectsForeignNotification(): void
    {
        $this->persistNotification('Al meu', '/case/1'); // renders a valid token for the current user
        $other = $this->makeUser($this->prefix . '-foreign');
        $foreign = $this->persistNotification('Al altcuiva', '/case/1', $other);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/notifications/dropdown');
        $token = $crawler->filter('[data-notification-link-token-value]')->first()->attr('data-notification-link-token-value');

        $this->client->request('POST', '/notifications/' . $foreign->getId() . '/read', ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testMarkReadWithoutCsrfIsRejected(): void
    {
        $notif = $this->persistNotification('Fără token', null);
        $this->em->flush();

        $this->client->request('POST', '/notifications/' . $notif->getId() . '/read', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testReadAllMarksEveryUnread(): void
    {
        $this->persistNotification('u1', null);
        $this->persistNotification('u2', null);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/notifications');
        $this->client->submit($crawler->filter('form[action$="/read-all"]')->form());

        self::assertResponseStatusCodeSame(302);
        $this->em->clear();
        $repo = $this->em->getRepository(Notification::class);
        self::assertSame(0, $repo->countUnreadByUser($this->user));
    }

    private function persistNotification(string $title, ?string $link, ?User $user = null): Notification
    {
        $notif = (new Notification())
            ->setUser($user ?? $this->user)
            ->setType('deadline_alert')
            ->setChannel(NotificationChannel::IN_APP)
            ->setTitle($title)
            ->setMessage('Corp mesaj pentru ' . $title)
            ->setResourceLink($link);
        $this->em->persist($notif);

        return $notif;
    }

    private function makeUser(string $prefix): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($prefix . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?",
            [$this->prefix . '%'],
        );
        $conn->executeStatement("DELETE FROM audit_log WHERE user_id IN (SELECT id FROM user WHERE email LIKE ?)", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->prefix . '%']);
        parent::tearDown();
    }
}
