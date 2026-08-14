<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\BlockedCaseAlert;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\NotificationChannel;
use App\Enum\PortalEventType;
use App\Event\BlockedCaseAlertEvent;
use App\Event\DeadlineAlertEvent;
use App\Event\PortalEventDetectedEvent;
use App\EventSubscriber\EmailNotificationSubscriber;
use App\Service\Notification\AlertCadence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\Marking;

/**
 * Exercises the real dispatcher (null mailer transport that still renders the
 * Twig templates and records the message) to catch template / i18n errors and
 * confirm the in-app row is persisted with the right channel.
 */
final class EmailNotificationSubscriberIntegrationTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;
    private EmailNotificationSubscriber $subscriber;
    private User $user;
    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->subscriber = static::getContainer()->get(EmailNotificationSubscriber::class);
        $this->prefix = 'notif-sub-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    public function testDeadlineAlertSendsEmailAndPersistsInApp(): void
    {
        $case = $this->persistCase();
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType(DeadlineType::PRESCRIPTIE);
        $deadline->setDeadlineDate(new \DateTimeImmutable('+5 days'));
        $this->em->persist($deadline);
        $this->em->flush();

        $this->subscriber->onDeadlineAlert(new DeadlineAlertEvent($deadline, 5));

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        self::assertNotNull($email);
        $this->assertEmailHtmlBodyContains($email, 'NCC art. 2517');

        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'deadline_alert',
        ]);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationChannel::IN_APP, $notifications[0]->getChannel());
    }

    public function testWorkflowEnteredEventTriggersNotificationViaDispatcher(): void
    {
        $case = $this->persistCase();

        static::getContainer()->get('event_dispatcher')->dispatch(
            new EnteredEvent($case, new Marking()),
            'workflow.legal_case.entered.ORDONANTA_EMISA',
        );

        $this->assertEmailCount(1);
        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'case_status',
        ]);
        self::assertCount(1, $notifications);
    }

    public function testInAnulareEnteredEventPersistsSingleCaseStatusNotification(): void
    {
        $case = $this->persistCase();

        static::getContainer()->get('event_dispatcher')->dispatch(
            new EnteredEvent($case, new Marking()),
            'workflow.legal_case.entered.IN_ANULARE',
        );

        $this->assertEmailCount(1);
        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'case_status',
        ]);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationChannel::IN_APP, $notifications[0]->getChannel());
    }

    public function testDosarInregistratEnteredEventPersistsSingleCaseStatusNotification(): void
    {
        $case = $this->persistCase();

        static::getContainer()->get('event_dispatcher')->dispatch(
            new EnteredEvent($case, new Marking()),
            'workflow.legal_case.entered.DOSAR_INREGISTRAT',
        );

        $this->assertEmailCount(1);
        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'case_status',
        ]);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationChannel::IN_APP, $notifications[0]->getChannel());
    }

    public function testPortalEventSendsEmailAndPersistsInApp(): void
    {
        $case = $this->persistCase();
        $portalEvent = new CourtPortalEvent();
        $portalEvent->setLegalCase($case);
        $portalEvent->setEventType(PortalEventType::HEARING_SCHEDULED);
        $portalEvent->setDescription('Termen de judecată 20.03.2026');
        $this->em->persist($portalEvent);
        $this->em->flush();

        $this->subscriber->onPortalEventDetected(new PortalEventDetectedEvent($case, $portalEvent));

        $this->assertEmailCount(1);
        // After consolidation the dispatcher persists the single in-app portal_event row.
        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'portal_event',
        ]);
        self::assertCount(1, $notifications);
    }

    /**
     * A blocked case has no term to lean on, so the email has to name the fact that is
     * missing and the act that unblocks it, apart from the explanation, and carry a
     * link straight into the case. The regularization wording stays conditional on
     * purpose: the platform cannot know whether the notice arrived.
     */
    public function testBlockedCaseAlertSendsEmailStatingWhatIsMissingAndWhatToDo(): void
    {
        $case = $this->persistCase();
        $translator = static::getContainer()->get('translator');

        $this->subscriber->onBlockedCaseAlert(new BlockedCaseAlertEvent(
            $case,
            BlockedCaseAlert::REGULARIZATION_NOTICE_DATE_MISSING,
        ));

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        self::assertNotNull($email);

        $params = ['%case%' => $case->getCourtCaseNumber()];
        $this->assertEmailHtmlBodyContains($email, $translator->trans('email.blocked_case.missing_label'));
        $this->assertEmailHtmlBodyContains($email, $translator->trans('email.blocked_case.action_label'));
        $this->assertEmailHtmlBodyContains(
            $email,
            $translator->trans(BlockedCaseAlert::REGULARIZATION_NOTICE_DATE_MISSING->emailActionKey(), $params),
        );
        $this->assertEmailHtmlBodyContains($email, '/case/' . $case->getId());

        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'blocked_case_alert',
        ]);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationChannel::IN_APP, $notifications[0]->getChannel());
    }

    /**
     * The condition behind a blocked case stays true until the lawyer acts, and the job
     * that checks it runs every morning, so throttling is not a refinement here: it is
     * what keeps the alert from becoming the daily mail the lawyer filters away. The
     * weekly key has to stop BOTH channels, which is what is read end to end through
     * the real dispatcher and the real notification table.
     */
    public function testASecondAlertUnderTheSameWeeklyKeyDeliversNothingOnEitherChannel(): void
    {
        $case = $this->persistCase();
        $key = AlertCadence::weekly(
            'blocked_case:' . BlockedCaseAlert::STAMP_DUTY_DUE->value,
            (int) $case->getId(),
            new \DateTimeImmutable('2026-08-03'),
        );

        $this->subscriber->onBlockedCaseAlert(new BlockedCaseAlertEvent($case, BlockedCaseAlert::STAMP_DUTY_DUE, $key));
        // Friday of the same week: the same condition, the same key, a run that must
        // stay silent.
        $this->subscriber->onBlockedCaseAlert(new BlockedCaseAlertEvent($case, BlockedCaseAlert::STAMP_DUTY_DUE, $key));

        // The email is the noisier of the two channels, so it is the one the key has
        // to gate; the in-app row was already single before the gate moved up.
        $this->assertEmailCount(1);
        self::assertCount(1, $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'blocked_case_alert',
        ]));

        // The week after is a new message, or a case blocked for a month would be
        // mentioned once and then never again.
        $this->subscriber->onBlockedCaseAlert(new BlockedCaseAlertEvent(
            $case,
            BlockedCaseAlert::STAMP_DUTY_DUE,
            AlertCadence::weekly(
                'blocked_case:' . BlockedCaseAlert::STAMP_DUTY_DUE->value,
                (int) $case->getId(),
                new \DateTimeImmutable('2026-08-10'),
            ),
        ));

        $this->assertEmailCount(2);
    }

    private function persistCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCaseNumber('LR-' . uniqid());
        $case->setCourtCaseNumber('300/211/2026');
        $case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE cpe FROM court_portal_event cpe JOIN legal_case lc ON cpe.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->prefix . '%']);
        parent::tearDown();
    }
}
