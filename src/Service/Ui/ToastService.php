<?php

declare(strict_types=1);

namespace App\Service\Ui;

use App\Entity\User;
use App\Service\Mercure\MercureTokenService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class ToastService
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly ?HubInterface $hub = null,
    ) {
    }

    public function success(string $message): void
    {
        $this->push('success', $message);
    }

    public function error(string $message): void
    {
        $this->push('error', $message);
    }

    public function warning(string $message): void
    {
        $this->push('warning', $message);
    }

    public function info(string $message): void
    {
        $this->push('info', $message);
    }

    private function push(string $variant, string $message): void
    {
        $session = $this->session();
        if ($session instanceof Session) {
            $session->getFlashBag()->add('toast.'.$variant, $message);
        }

        $user = $this->security->getUser();
        if ($user instanceof User && $this->hub !== null) {
            $topic = MercureTokenService::userNotificationTopic($user);
            $this->hub->publish(new Update(
                $topic,
                json_encode(['type' => 'toast', 'variant' => $variant, 'message' => $message], \JSON_THROW_ON_ERROR),
                private: true,
            ));
        }
    }

    private function session(): ?Session
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();
        return $session instanceof Session ? $session : null;
    }
}
