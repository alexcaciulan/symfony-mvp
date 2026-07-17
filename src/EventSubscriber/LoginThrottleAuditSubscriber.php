<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Records a security audit entry whenever login throttling trips (audit #16), so brute-force
 * attempts against an account leave a forensic trail.
 */
final class LoginThrottleAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginFailureEvent::class => 'onLoginFailure'];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!$event->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        $request = $event->getRequest();
        $username = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');

        // A DB hiccup here must not turn a throttled login into a 500.
        try {
            $this->auditLogService->log(
                action: 'login_throttled',
                entityType: 'User',
                // entityId is VARCHAR(50); the username is attacker-controlled, so cap it to keep
                // the insert from silently failing. The full value stays in newData (JSON, uncapped).
                entityId: mb_substr($username, 0, 50),
                newData: ['username' => $username, 'ip' => $request->getClientIp()],
                category: AuditLogService::CATEGORY_SECURITY,
            );
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to audit a login throttle event: ' . $e->getMessage());
        }
    }
}
