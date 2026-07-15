<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\NotificationChannel;
use App\Enum\PortalEventType;
use App\Event\DeadlineAlertEvent;
use App\Event\PortalEventDetectedEvent;
use App\EventSubscriber\EmailNotificationSubscriber;
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

    public function testPortalEventSendsEmailButDoesNotPersistInApp(): void
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
        // persistInApp is false for portal events (CaseMonitoringService owns the in-app row).
        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => 'portal_event',
        ]);
        self::assertCount(0, $notifications);
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
