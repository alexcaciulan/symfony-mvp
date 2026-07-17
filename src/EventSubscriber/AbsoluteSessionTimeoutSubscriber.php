<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Absolute session cap: forces a re-login once a session is older than the TTL, regardless of
 * activity (the 30-min idle expiry only covers inactivity). Complements audit #7. login_at is
 * seeded lazily on the first authenticated request, so existing sessions are never force-expired.
 */
final class AbsoluteSessionTimeoutSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY_LOGIN_AT = 'auth.login_at';
    private const ALLOWED_ROUTES = ['app_login', 'app_logout'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly int $absoluteSessionTtl,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 7: after the firewall (8) populates the token, before the email-verification
        // gate (6) so an expired session goes to login regardless of verification state.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->security->getUser() instanceof User) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $loginAt = $session->get(self::SESSION_KEY_LOGIN_AT);

        if (!\is_int($loginAt)) {
            // First authenticated request for this session: seed and never expire on it.
            $session->set(self::SESSION_KEY_LOGIN_AT, time());

            return;
        }

        if (time() - $loginAt < $this->absoluteSessionTtl) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (\is_string($route) && \in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $session->invalidate();
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }
}
