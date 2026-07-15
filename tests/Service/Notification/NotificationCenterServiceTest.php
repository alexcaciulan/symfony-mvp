<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Service\Notification\NotificationCenterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationCenterServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NotificationCenterService $service;
    private User $user;
    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(NotificationCenterService::class);
        $this->prefix = 'center-svc-' . uniqid();
        $this->user = $this->makeUser($this->prefix);
    }

    public function testMarkReadReturnsRowForOwnerAndFlipsFlag(): void
    {
        $notif = $this->persist();
        $this->em->flush();

        $result = $this->service->markRead((int) $notif->getId(), $this->user);

        self::assertInstanceOf(Notification::class, $result);
        self::assertTrue($result->isRead());
        self::assertNotNull($result->getReadAt());
    }

    public function testMarkReadReturnsNullForForeignOwner(): void
    {
        $other = $this->makeUser($this->prefix . '-x');
        $foreign = $this->persist($other);
        $this->em->flush();

        self::assertNull($this->service->markRead((int) $foreign->getId(), $this->user));
    }

    public function testMarkAllReadReturnsFlippedCount(): void
    {
        $this->persist();
        $this->persist();
        $read = $this->persist();
        $read->setIsRead(true);
        $this->em->flush();

        self::assertSame(2, $this->service->markAllRead($this->user));
        self::assertSame(0, $this->service->unreadCount($this->user));
    }

    private function persist(?User $user = null): Notification
    {
        $notif = (new Notification())
            ->setUser($user ?? $this->user)
            ->setType('portal_event')
            ->setChannel(NotificationChannel::IN_APP)
            ->setTitle('T')
            ->setMessage('M');
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
        $conn->executeStatement("DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM audit_log WHERE user_id IN (SELECT id FROM user WHERE email LIKE ?)", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->prefix . '%']);
        parent::tearDown();
    }
}
