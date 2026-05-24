<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TableControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'table-ctrl-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = $this->makeUser($hasher, $this->prefix . '@test.com');
        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    public function testReturnsTabulatorEnvelope(): void
    {
        $this->makeNotification($this->user, 'ORDONANTA-MARKER');
        $this->em->flush();

        $this->client->request('GET', '/api/table/notifications?page=1&size=25');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('last_page', $payload);
        self::assertArrayHasKey('data', $payload);
        self::assertSame(1, $payload['last_row']);
        self::assertSame('ORDONANTA-MARKER', $payload['data'][0]['type']);
    }

    public function testScopesToCurrentUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = $this->makeUser($hasher, $this->prefix . '-other@test.com');
        $this->makeNotification($other, 'OTHER-USER-MARKER');
        $this->em->flush();

        $this->client->request('GET', '/api/table/notifications');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(0, $payload['last_row']);
    }

    public function testUnknownTableKeyReturns404(): void
    {
        $this->client->request('GET', '/api/table/does_not_exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $anon = static::createClient();
        $anon->request('GET', '/api/table/notifications');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', $anon->getResponse()->headers->get('Location'));
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

    private function makeNotification(User $user, string $type): void
    {
        $n = (new Notification())
            ->setUser($user)
            ->setType($type)
            ->setChannel(NotificationChannel::IN_APP)
            ->setTitle('Title ' . uniqid())
            ->setMessage('msg')
            ->setIsRead(false);
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
