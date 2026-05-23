<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\DeadlineType;
use App\Enum\PortalEventType;
use App\Event\DeadlineAlertEvent;
use App\Event\MissingCommunicationDateEvent;
use App\Event\PortalEventDetectedEvent;
use App\EventSubscriber\EmailNotificationSubscriber;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailNotificationSubscriberTest extends TestCase
{
    public function testDeadlineAlertPrescriptionUsesMaterialLawKeys(): void
    {
        $deadline = $this->deadline(DeadlineType::PRESCRIPTIE);
        $spy = new SpyNotificationDispatcher();

        $this->subscriber($spy)->onDeadlineAlert(new DeadlineAlertEvent($deadline, 5));

        $request = $spy->last();
        self::assertSame('deadline_alert', $request->type);
        self::assertSame('warning', $request->variant);
        self::assertSame('notification.deadline_alert.prescriptie.title', $request->title);
        self::assertSame('notification.deadline_alert.prescriptie.upcoming', $request->message);
        self::assertSame('emails/deadline_alert.html.twig', $request->emailTemplate);
        self::assertTrue($request->persistInApp);
        self::assertTrue($request->emailContext['isMaterial']);
        self::assertFalse($request->emailContext['isExpired']);
    }

    public function testDeadlineAlertProceduralExpiredUsesErrorVariant(): void
    {
        $deadline = $this->deadline(DeadlineType::CERERE_IN_ANULARE);
        $spy = new SpyNotificationDispatcher();

        $this->subscriber($spy)->onDeadlineAlert(new DeadlineAlertEvent($deadline, -2));

        $request = $spy->last();
        self::assertSame('error', $request->variant);
        self::assertSame('notification.deadline_alert.procedural.title', $request->title);
        self::assertSame('notification.deadline_alert.procedural.expired', $request->message);
        self::assertFalse($request->emailContext['isMaterial']);
        self::assertTrue($request->emailContext['isExpired']);
    }

    public function testPortalEventDoesNotPersistInApp(): void
    {
        $case = $this->case();
        $portalEvent = (new CourtPortalEvent())
            ->setLegalCase($case)
            ->setEventType(PortalEventType::HEARING_SCHEDULED)
            ->setDescription('Termen 20.03.2026');
        $spy = new SpyNotificationDispatcher();

        $this->subscriber($spy)->onPortalEventDetected(new PortalEventDetectedEvent($case, $portalEvent));

        $request = $spy->last();
        self::assertSame('portal_update', $request->type);
        self::assertSame('emails/portal_event.html.twig', $request->emailTemplate);
        self::assertFalse($request->persistInApp);
    }

    public function testWorkflowOrdonantaEmisaMapsToSuccessStatusNotification(): void
    {
        $spy = new SpyNotificationDispatcher();

        $this->subscriber($spy)->onOrdonantaEmisa(new EnteredEvent($this->case(), new Marking()));

        $request = $spy->last();
        self::assertSame('case_status', $request->type);
        self::assertSame('success', $request->variant);
        self::assertSame('notification.case_status.ordonanta_emisa.title', $request->title);
        self::assertSame('emails/case_status.html.twig', $request->emailTemplate);
        self::assertTrue($request->persistInApp);
    }

    public function testMissingCommunicationDateBuildsWarning(): void
    {
        $spy = new SpyNotificationDispatcher();

        $this->subscriber($spy)->onMissingCommunicationDate(new MissingCommunicationDateEvent($this->case()));

        $request = $spy->last();
        self::assertSame('missing_communication_date', $request->type);
        self::assertSame('warning', $request->variant);
        self::assertSame('emails/missing_communication_date.html.twig', $request->emailTemplate);
    }

    public function testWorkflowIgnoresNonLegalCaseSubject(): void
    {
        $spy = new SpyNotificationDispatcher();

        $this->subscriber($spy)->onSomatieTrimisa(new EnteredEvent(new \stdClass(), new Marking()));

        self::assertCount(0, $spy->captured);
    }

    public function testWorkflowIgnoresLegalCaseWithoutId(): void
    {
        $case = new LegalCase();
        $case->setUser($this->user());
        $case->setCaseNumber('LR-no-id');
        $spy = new SpyNotificationDispatcher();

        $this->subscriber($spy)->onSomatieTrimisa(new EnteredEvent($case, new Marking()));

        self::assertCount(0, $spy->captured);
    }

    private function subscriber(NotificationDispatcherInterface $dispatcher): EmailNotificationSubscriber
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/case/1');

        return new EmailNotificationSubscriber($dispatcher, $this->echoTranslator(), $urlGenerator, 'http://test');
    }

    private function case(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user());
        $case->setCaseNumber('LR-1001');
        $case->setCourtCaseNumber('200/211/2026');
        (new \ReflectionProperty(LegalCase::class, 'id'))->setValue($case, 1);

        return $case;
    }

    private function user(): User
    {
        $user = new User();
        $user->setEmail('lawyer@test.com');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 7);

        return $user;
    }

    private function deadline(DeadlineType $type): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($this->case());
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable('2026-06-01'));

        return $deadline;
    }

    private function echoTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        // Echo the key back so assertions can verify which translation key was chosen.
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}

final class SpyNotificationDispatcher implements NotificationDispatcherInterface
{
    /** @var list<NotificationDispatch> */
    public array $captured = [];

    public function dispatch(NotificationDispatch $request): void
    {
        $this->captured[] = $request;
    }

    public function last(): NotificationDispatch
    {
        return $this->captured[array_key_last($this->captured)];
    }
}
