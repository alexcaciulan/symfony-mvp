<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BaseLayoutSmokeTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testHomeRendersForAnonymousUser(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-controller="preline-init"', $body);
        $this->assertStringContainsString('data-controller="toast"', $body);
        $this->assertStringContainsString('view-transition', $body);
    }

    public function testLoginRendersForAnonymousUser(): void
    {
        $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-controller="dark-mode-toggle"', $body);
    }

    public function testHomeRendersForAuthenticatedUser(): void
    {
        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'admin@example.com'])
            ?? $userRepository->findOneBy([]);

        if (!$user instanceof User) {
            $this->markTestSkipped('No user available in test database — run app:create-test-users.');
        }

        $this->client->loginUser($user);
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-mercure-topic-value="user/'.$user->getId().'/notification"', $body);
    }
}
