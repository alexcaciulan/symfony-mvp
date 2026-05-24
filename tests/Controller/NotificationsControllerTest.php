<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
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

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    public function testRendersDataTableContainer(): void
    {
        $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-controller="tabulator"', $content);
        self::assertStringContainsString('data-tabulator-config-value', $content);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->prefix . '%']);
        parent::tearDown();
    }
}
