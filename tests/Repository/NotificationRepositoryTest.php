<?php

namespace App\Tests\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class NotificationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NotificationRepository $repo;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(Notification::class);
        $this->testPrefix = 'repo-notif-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createNotification(bool $isRead = false, ?User $user = null): Notification
    {
        $notif = new Notification();
        $notif->setUser($user ?? $this->user);
        $notif->setType('test');
        $notif->setChannel(NotificationChannel::IN_APP);
        $notif->setTitle('Test Notification');
        $notif->setMessage('Test message');
        $notif->setIsRead($isRead);
        $this->em->persist($notif);

        return $notif;
    }

    public function testCountUnreadByUserFiltersCorrectly(): void
    {
        $this->createNotification(false); // unread
        $this->createNotification(false); // unread
        $this->createNotification(true);  // read
        $this->em->flush();

        $this->assertSame(2, $this->repo->countUnreadByUser($this->user));
    }

    public function testCountUnreadByUserIgnoresOtherUsers(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail($this->testPrefix . '-other@test.com');
        $other->setPassword($hasher->hashPassword($other, 'test'));
        $other->setIsVerified(true);
        $this->em->persist($other);

        $this->createNotification(false); // user's unread
        $this->createNotification(false, $other); // other's unread
        $this->em->flush();

        $this->assertSame(1, $this->repo->countUnreadByUser($this->user));
    }

    public function testFindRecentByUserRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createNotification();
        }
        $this->em->flush();

        $result = $this->repo->findRecentByUser($this->user, 3);
        $this->assertCount(3, $result);
    }

    public function testFindRecentByUserOrdersByCreatedAtDesc(): void
    {
        $this->createNotification();
        $this->createNotification();
        $this->createNotification();
        $this->em->flush();

        $result = $this->repo->findRecentByUser($this->user);

        for ($i = 0; $i < count($result) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $result[$i + 1]->getCreatedAt()->getTimestamp(),
                $result[$i]->getCreatedAt()->getTimestamp()
            );
        }
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
