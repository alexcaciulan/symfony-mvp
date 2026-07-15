<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PruneNotificationsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NotificationRepository $repo;
    private User $user;
    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(Notification::class);
        $this->prefix = 'prune-cmd-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:prune-notifications'));
    }

    private function backdated(bool $isRead, string $modifier): void
    {
        $n = (new Notification())
            ->setUser($this->user)
            ->setType('test')
            ->setChannel(NotificationChannel::IN_APP)
            ->setTitle('T')
            ->setMessage('M')
            ->setIsRead($isRead);
        (new \ReflectionProperty(Notification::class, 'createdAt'))->setValue($n, new \DateTimeImmutable($modifier));
        $this->em->persist($n);
    }

    public function testDryRunReportsButDoesNotDelete(): void
    {
        $this->backdated(true, '-100 days');
        $this->backdated(false, '-10 days');
        $this->em->flush();

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 notification', $tester->getDisplay());
        self::assertSame(2, $this->repo->count(['user' => $this->user]));
    }

    public function testPruneDeletesObsolete(): void
    {
        $this->backdated(true, '-100 days');   // read + old -> deleted
        $this->backdated(false, '-400 days');  // past hard cap -> deleted
        $this->backdated(false, '-10 days');   // recent -> kept
        $this->em->flush();

        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(1, $this->repo->count(['user' => $this->user]));
    }

    public function testRejectsNonPositiveDays(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--read-days' => '0']);

        self::assertNotSame(0, $exit);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->prefix . '%']);
        parent::tearDown();
    }
}
