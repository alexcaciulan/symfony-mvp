<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotificationDispatcherTest extends TestCase
{
    public function testFullDispatchSendsEmailPersistsInAppAndPublishesToast(): void
    {
        $mailer = new SpyMailer();
        $hub = new SpyHub();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(Notification::class));
        $em->expects(self::once())->method('flush');

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', $hub, new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: 'Subject'));

        self::assertCount(1, $mailer->sent);
        self::assertCount(1, $hub->updates);
        self::assertSame(['user/5/notification'], $hub->updates[0]->getTopics());
        self::assertTrue($hub->updates[0]->isPrivate());

        $payload = json_decode($hub->updates[0]->getData(), true);
        self::assertSame('toast', $payload['type']);
        self::assertSame('warning', $payload['variant']);
        self::assertSame('Title', $payload['message']);
    }

    public function testNoEmailWhenSubjectIsNull(): void
    {
        $mailer = new SpyMailer();
        $hub = new SpyHub();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', $hub, new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: null));

        self::assertCount(0, $mailer->sent);
        self::assertCount(1, $hub->updates);
    }

    public function testSkipsInAppWhenPersistInAppFalse(): void
    {
        $mailer = new SpyMailer();
        $hub = new SpyHub();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', $hub, new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: false, emailSubject: 'Subject'));

        self::assertCount(1, $mailer->sent);
        self::assertCount(1, $hub->updates);
    }

    public function testNullHubDoesNotPublishAndDoesNotError(): void
    {
        $mailer = new SpyMailer();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', null, new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: 'Subject'));

        self::assertCount(1, $mailer->sent);
    }

    public function testMailerFailureIsIsolatedFromOtherChannels(): void
    {
        $hub = new SpyHub();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $dispatcher = new NotificationDispatcher(new ThrowingMailer(), $em, $this->translator(), 'noreply@x.test', $hub, new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: 'Subject'));

        // Email blew up, but in-app persist and Mercure toast still ran.
        self::assertCount(1, $hub->updates);
    }

    public function testHubFailureIsIsolatedFromOtherChannels(): void
    {
        $mailer = new SpyMailer();
        $hub = new SpyHub(throw: new \RuntimeException('hub down'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $dispatcher = new NotificationDispatcher($mailer, $em, $this->translator(), 'noreply@x.test', $hub, new NullLogger());
        $dispatcher->dispatch($this->request(persistInApp: true, emailSubject: 'Subject'));

        self::assertCount(1, $mailer->sent);
        self::assertCount(0, $hub->updates);
    }

    private function request(bool $persistInApp, ?string $emailSubject): NotificationDispatch
    {
        return new NotificationDispatch(
            user: $this->user(),
            legalCase: null,
            type: 'deadline_alert',
            title: 'Title',
            message: 'Message',
            resourceLink: '/case/1',
            variant: 'warning',
            emailSubject: $emailSubject,
            emailTemplate: $emailSubject === null ? null : 'emails/case_status.html.twig',
            emailContext: ['heading' => 'H', 'body' => 'B', 'caseUrl' => 'http://x/case/1'],
            persistInApp: $persistInApp,
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

final class SpyHub implements HubInterface
{
    /** @var list<Update> */
    public array $updates = [];

    public function __construct(private readonly ?\Throwable $throw = null) {}

    public function publish(Update $update): string
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $this->updates[] = $update;

        return 'urn:uuid:test-event-' . count($this->updates);
    }

    public function getPublicUrl(): string
    {
        return 'http://mercure-spy.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }
}
