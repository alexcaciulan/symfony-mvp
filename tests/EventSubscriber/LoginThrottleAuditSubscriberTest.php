<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AuditLog;
use App\EventSubscriber\LoginThrottleAuditSubscriber;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class LoginThrottleAuditSubscriberTest extends KernelTestCase
{
    public function testThrottleTripIsAudited(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $subscriber = new LoginThrottleAuditSubscriber(static::getContainer()->get(AuditLogService::class), $em);

        $username = 'throttle-' . uniqid() . '@test.com';
        $subscriber->onLoginFailure($this->failureEvent(new TooManyLoginAttemptsAuthenticationException(), $username));

        $log = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'login_throttled', 'entityId' => $username]);
        self::assertNotNull($log, 'A throttle trip must leave a security audit entry.');
        self::assertSame(AuditLogService::CATEGORY_SECURITY, $log->getCategory());

        $em->remove($log);
        $em->flush();
    }

    public function testOrdinaryFailureIsNotAudited(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $subscriber = new LoginThrottleAuditSubscriber(static::getContainer()->get(AuditLogService::class), $em);

        $username = 'plain-fail-' . uniqid() . '@test.com';
        $subscriber->onLoginFailure($this->failureEvent(new BadCredentialsException(), $username));

        self::assertNull($em->getRepository(AuditLog::class)->findOneBy(['entityId' => $username]));
    }

    public function testOverlongUsernameIsTruncatedInEntityId(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $subscriber = new LoginThrottleAuditSubscriber(static::getContainer()->get(AuditLogService::class), $em);

        $username = str_repeat('a', 80) . '@test.com';
        $subscriber->onLoginFailure($this->failureEvent(new TooManyLoginAttemptsAuthenticationException(), $username));

        $log = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'login_throttled', 'entityId' => mb_substr($username, 0, 50)]);
        self::assertNotNull($log, 'An overlong username must still leave an audit entry (entityId capped at 50).');
        self::assertSame(50, mb_strlen((string) $log->getEntityId()));
        self::assertSame($username, $log->getNewData()['username'], 'The full username stays in newData.');

        $em->remove($log);
        $em->flush();
    }

    private function failureEvent(\Throwable $exception, string $username): LoginFailureEvent
    {
        $request = new Request();
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return new LoginFailureEvent($exception, $this->createStub(AuthenticatorInterface::class), $request, null, 'main');
    }
}
