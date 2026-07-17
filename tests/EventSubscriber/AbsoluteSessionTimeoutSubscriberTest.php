<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\AbsoluteSessionTimeoutSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AbsoluteSessionTimeoutSubscriberTest extends TestCase
{
    private const TTL = 43200;

    public function testSeedsLoginAtOnFirstRequestWithoutExpiring(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $event = $this->dispatch($this->authedUser(), $session, 'app_dashboard');

        self::assertNull($event->getResponse(), 'A first authenticated request must be seeded, not expired.');
        self::assertIsInt($session->get('auth.login_at'));
    }

    public function testKeepsFreshSession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('auth.login_at', time() - 60);
        $event = $this->dispatch($this->authedUser(), $session, 'app_dashboard');

        self::assertNull($event->getResponse());
    }

    public function testExpiredSessionRedirectsToLogin(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('auth.login_at', time() - self::TTL - 1);
        $event = $this->dispatch($this->authedUser(), $session, 'app_dashboard');

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testAnonymousRequestIsIgnored(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('auth.login_at', time() - self::TTL - 1);
        $event = $this->dispatch(null, $session, 'app_dashboard');

        self::assertNull($event->getResponse());
    }

    private function authedUser(): User
    {
        $user = new User();
        $user->setEmail('timeout@test.com');

        return $user;
    }

    private function dispatch(?User $user, Session $session, string $route): RequestEvent
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/login');

        $subscriber = new AbsoluteSessionTimeoutSubscriber($security, $urlGenerator, self::TTL);

        $request = new Request();
        $request->setSession($session);
        $request->attributes->set('_route', $route);

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($event);

        return $event;
    }
}
