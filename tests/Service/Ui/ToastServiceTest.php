<?php

declare(strict_types=1);

namespace App\Tests\Service\Ui;

use App\Entity\User;
use App\Service\Ui\ToastService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class ToastServiceTest extends TestCase
{
    public function testSuccessAddsFlashWithCorrectKey(): void
    {
        $session = $this->makeSession();
        $stack = $this->makeStackWithSession($session);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $service = new ToastService($stack, $security, null);
        $service->success('Salvat cu succes');

        $this->assertSame(['Salvat cu succes'], $session->getFlashBag()->get('toast.success'));
    }

    public function testErrorWarningInfoUseSeparateBuckets(): void
    {
        $session = $this->makeSession();
        $stack = $this->makeStackWithSession($session);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $service = new ToastService($stack, $security, null);
        $service->error('Eroare X');
        $service->warning('Atenție Y');
        $service->info('Info Z');

        $this->assertSame(['Eroare X'], $session->getFlashBag()->get('toast.error'));
        $this->assertSame(['Atenție Y'], $session->getFlashBag()->get('toast.warning'));
        $this->assertSame(['Info Z'], $session->getFlashBag()->get('toast.info'));
    }

    public function testPublishesToMercureWhenUserAuthenticated(): void
    {
        $session = $this->makeSession();
        $stack = $this->makeStackWithSession($session);
        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, 99);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) {
                $this->assertSame(['user/99/notification'], $update->getTopics());
                $payload = json_decode($update->getData(), true);
                $this->assertSame('toast', $payload['type']);
                $this->assertSame('success', $payload['variant']);
                $this->assertSame('hi', $payload['message']);
                return true;
            }));

        $service = new ToastService($stack, $security, $hub);
        $service->success('hi');
    }

    public function testNoMercurePublishWhenAnonymous(): void
    {
        $session = $this->makeSession();
        $stack = $this->makeStackWithSession($session);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->never())->method('publish');

        $service = new ToastService($stack, $security, $hub);
        $service->info('public');
    }

    private function makeSession(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private function makeStackWithSession(Session $session): RequestStack
    {
        $stack = new RequestStack();
        $request = new Request();
        $request->setSession($session);
        $stack->push($request);
        return $stack;
    }
}
