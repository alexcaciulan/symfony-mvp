<?php

declare(strict_types=1);

namespace App\Tests\Service\Table;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Service\Table\TableDataService;
use App\Service\Table\TableQuery;
use App\Service\Table\TableRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TableDataServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TableDataService $service;
    private \App\Service\Table\TableDefinitionInterface $definition;
    private User $user;
    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(TableDataService::class);
        $this->definition = static::getContainer()->get(TableRegistry::class)->get('notifications');
        $this->prefix = 'table-svc-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = $this->makeUser($hasher, $this->prefix . '@test.com');
        $this->em->flush();
    }

    public function testPaginatesAndCountsTotal(): void
    {
        for ($i = 0; $i < 15; ++$i) {
            $this->makeNotification($this->user, 'portal_update', false);
        }
        $this->em->flush();

        $result = $this->service->query($this->definition, $this->user, new TableQuery(page: 1, pageSize: 10));

        self::assertCount(10, $result->rows);
        self::assertSame(15, $result->total);
        self::assertSame(2, $result->lastPage());
    }

    public function testScopesToUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = $this->makeUser($hasher, $this->prefix . '-other@test.com');
        $this->makeNotification($this->user, 'portal_update', false);
        $this->makeNotification($other, 'portal_update', false);
        $this->makeNotification($other, 'deadline_alert', false);
        $this->em->flush();

        $result = $this->service->query($this->definition, $this->user, new TableQuery());

        self::assertSame(1, $result->total);
    }

    public function testBoolFilter(): void
    {
        $this->makeNotification($this->user, 'portal_update', true);
        $this->makeNotification($this->user, 'portal_update', false);
        $this->makeNotification($this->user, 'portal_update', false);
        $this->em->flush();

        $read = $this->service->query($this->definition, $this->user, new TableQuery(filters: ['isRead' => true]));
        self::assertSame(1, $read->total);

        $unread = $this->service->query($this->definition, $this->user, new TableQuery(filters: ['isRead' => false]));
        self::assertSame(2, $unread->total);
    }

    public function testTextFilter(): void
    {
        $this->makeNotification($this->user, 'portal_update', false);
        $this->makeNotification($this->user, 'deadline_alert', false);
        $this->em->flush();

        $result = $this->service->query($this->definition, $this->user, new TableQuery(filters: ['type' => 'portal_update']));

        self::assertSame(1, $result->total);
        self::assertSame('portal_update', $result->rows[0]['type']);
    }

    public function testSortableColumnAppliesOrder(): void
    {
        $this->makeNotification($this->user, 'portal_update', true);
        $this->makeNotification($this->user, 'portal_update', false);
        $this->em->flush();

        $asc = $this->service->query($this->definition, $this->user, new TableQuery(sortField: 'isRead', sortDir: 'ASC'));
        self::assertFalse($asc->rows[0]['isRead']);

        $desc = $this->service->query($this->definition, $this->user, new TableQuery(sortField: 'isRead', sortDir: 'DESC'));
        self::assertTrue($desc->rows[0]['isRead']);
    }

    public function testNonSortableColumnIsIgnoredNotApplied(): void
    {
        $this->makeNotification($this->user, 'portal_update', false);
        $this->em->flush();

        // 'title' is not sortable; must not throw, must still return rows.
        $result = $this->service->query($this->definition, $this->user, new TableQuery(sortField: 'title', sortDir: 'ASC'));

        self::assertSame(1, $result->total);
    }

    public function testPageSizeIsClampedToAllowlist(): void
    {
        $this->makeNotification($this->user, 'portal_update', false);
        $this->em->flush();

        $result = $this->service->query($this->definition, $this->user, new TableQuery(pageSize: 999));

        self::assertSame(TableDataService::DEFAULT_PAGE_SIZE, $result->pageSize);
    }

    private function makeUser(UserPasswordHasherInterface $hasher, string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function makeNotification(User $user, string $type, bool $isRead): void
    {
        $n = (new Notification())
            ->setUser($user)
            ->setType($type)
            ->setChannel(NotificationChannel::IN_APP)
            ->setTitle('N ' . uniqid())
            ->setMessage('msg')
            ->setIsRead($isRead);
        $this->em->persist($n);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->prefix . '%']);
        parent::tearDown();
    }
}
