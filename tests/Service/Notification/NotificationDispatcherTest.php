<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotificationDispatcherTest extends TestCase
{
    public function testFullDispatchSendsEmailAndPersistsInApp(): void
    {
        $mailer = new SpyMailer();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(Notification::class));
        $em->expects(self::once())->method('flush');

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: 'Subject'));

        self::assertCount(1, $mailer->sent);
    }

    public function testNoEmailWhenSubjectIsNull(): void
    {
        $mailer = new SpyMailer();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: null));

        self::assertCount(0, $mailer->sent);
    }

    public function testSkipsInAppWhenPersistInAppFalse(): void
    {
        $mailer = new SpyMailer();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: false, emailSubject: 'Subject'));

        self::assertCount(1, $mailer->sent);
    }

    public function testMailerFailureIsIsolatedFromInApp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $dispatcher = new NotificationDispatcher(new ThrowingMailer(), $em, $this->translator(), 'noreply@x.test', new NullLogger());
        // Email blows up, but the in-app row still persists (fault isolation).
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: 'Subject'));
    }

    public function testPersistedRowCarriesTypeValueAndDedupKey(): void
    {
        $captured = null;
        $mailer = new SpyMailer();
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (Notification $n) use (&$captured): void {
            $captured = $n;
        });

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: null, dedupKey: 'payment:tx-1:charged'));

        self::assertInstanceOf(Notification::class, $captured);
        self::assertSame(NotificationType::DEADLINE_ALERT->value, $captured->getType());
        self::assertSame('payment:tx-1:charged', $captured->getDedupKey());
    }

    public function testDispatcherHasNoMercureHubDependency(): void
    {
        // Locks the decision: the notification center surfaces via client polling,
        // so the dispatcher must not depend on a Mercure hub (two channels only).
        $params = (new \ReflectionMethod(NotificationDispatcher::class, '__construct'))->getParameters();
        $types = array_map(
            static fn (\ReflectionParameter $p): ?string => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : null,
            $params,
        );

        self::assertNotContains(\Symfony\Component\Mercure\HubInterface::class, $types);
    }

    private function request(bool $persistInApp, ?string $emailSubject, ?string $dedupKey = null): NotificationDispatch
    {
        return new NotificationDispatch(
            user: $this->user(),
            legalCase: null,
            type: NotificationType::DEADLINE_ALERT,
            title: 'Title',
            message: 'Message',
            resourceLink: '/case/1',
            variant: 'warning',
            emailSubject: $emailSubject,
            emailTemplate: $emailSubject === null ? null : 'emails/case_status.html.twig',
            emailContext: ['heading' => 'H', 'body' => 'B', 'caseUrl' => 'http://x/case/1'],
            persistInApp: $persistInApp,
            dedupKey: $dedupKey,
        );
    }

    private function user(): User
    {
        $user = new User();
        $user->setEmail('lawyer@test.com');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 5);

        return $user;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('LexRecovery');

        return $translator;
    }
}

final class SpyMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sent[] = $message;
    }
}

final class ThrowingMailer implements MailerInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new \RuntimeException('smtp down');
    }
}
