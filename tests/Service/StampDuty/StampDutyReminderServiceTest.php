<?php

declare(strict_types=1);

namespace App\Tests\Service\StampDuty;

use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingChannel;
use App\Enum\NotificationType;
use App\Enum\PersonType;
use App\Enum\StampDutyStatus;
use App\Service\StampDuty\StampDutyReminderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The duty is chased from the filing, not from the court's notice: that notice is
 * served outside the platform and can reach the claimant instead of the lawyer.
 */
final class StampDutyReminderServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StampDutyReminderService $service;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(StampDutyReminderService::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('sd-reminder-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Reminder SRL');
        $creditor->setAddress('Str. Test 1, Cluj-Napoca');
        $this->em->persist($creditor);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setStatus(CaseStatus::CERERE_DEPUSA);
        $this->case->setAmount('5000.00');
        $this->case->setCurrency('RON');
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITARE_LA_DEPUNERE);
        $this->case->setFiledAt(new \DateTimeImmutable('2026-03-01'));
        $this->case->setFilingChannel(FilingChannel::REJUST);
        $this->em->persist($this->case);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM notification WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = ?)', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        parent::tearDown();
    }

    public function testNoReminderBeforeTheFirstScheduledDay(): void
    {
        self::assertNull($this->service->dueReminderIndex($this->case, new \DateTimeImmutable('2026-03-02')));
    }

    public function testFirstReminderGoesOutOnTheThirdDayAfterFiling(): void
    {
        self::assertSame(0, $this->service->dueReminderIndex($this->case, new \DateTimeImmutable('2026-03-04')));
    }

    /**
     * A skipped run must not fire the whole backlog at once: the schedule advances by
     * one message per run, so the lawyer gets a reminder, not an avalanche.
     */
    public function testAMissedRunCatchesUpWithASingleReminder(): void
    {
        $sent = $this->service->run(new \DateTimeImmutable('2026-06-01'));

        self::assertSame(1, $sent);
        $this->em->refresh($this->case);
        self::assertSame(1, $this->case->getStampDutyRemindersSent());
    }

    public function testTheScheduleStopsAfterThreeReminders(): void
    {
        $this->case->setStampDutyRemindersSent(3);
        $this->em->flush();

        self::assertNull($this->service->dueReminderIndex($this->case, new \DateTimeImmutable('2026-12-01')));
    }

    /**
     * Two runs on the same day must not stack two messages, even though the calendar
     * has already passed the next scheduled day. Without the same-day guard the
     * second run would find day 10 due and fire immediately after day 3.
     */
    public function testASecondRunOnTheSameDaySendsNothingEvenWhenTheNextStepIsDue(): void
    {
        // Far enough past filing that both the first and the second scheduled day
        // have been reached, so only the same-day guard can stop the second message.
        $this->service->run(new \DateTimeImmutable('2026-04-01'));
        $this->em->refresh($this->case);
        self::assertSame(1, $this->case->getStampDutyRemindersSent());

        self::assertSame(0, $this->service->run(new \DateTimeImmutable('2026-04-01')));

        $this->em->refresh($this->case);
        self::assertSame(1, $this->case->getStampDutyRemindersSent());
    }

    /** The next day, the schedule may advance again. */
    public function testTheScheduleAdvancesOnTheFollowingDay(): void
    {
        $this->service->run(new \DateTimeImmutable('2026-04-01'));

        self::assertSame(1, $this->service->run(new \DateTimeImmutable('2026-04-02')));
    }

    /**
     * An ECRIS number entered from the portal registers the case without any declared
     * filing date. Those are the cases where arrival at the court is provable, so they
     * must not be the ones the follow-up drops.
     */
    public function testACaseRegisteredWithoutADeclaredFilingDateIsStillChased(): void
    {
        $this->case->setFiledAt(null);
        $this->case->setStatus(CaseStatus::DOSAR_INREGISTRAT);
        $this->em->flush();

        // The status history is what dates the arrival when the lawyer never declared it.
        $history = new \App\Entity\CaseStatusHistory();
        $history->setLegalCase($this->case);
        $history->setOldStatus(CaseStatus::CERERE_GENERATA->value);
        $history->setNewStatus(CaseStatus::DOSAR_INREGISTRAT->value);
        $this->em->persist($history);
        $this->em->flush();
        $this->em->refresh($this->case);

        self::assertSame(1, $this->service->run(new \DateTimeImmutable('+30 days')));
    }

    /** A closed or rejected case has nothing left to chase. */
    public function testATerminalCaseIsNotChased(): void
    {
        $this->case->setStatus(CaseStatus::RESPINSA);
        $this->em->flush();

        self::assertSame(0, $this->service->run(new \DateTimeImmutable('2026-06-01')));
    }

    public function testMutingStopsTheReminders(): void
    {
        $this->service->mute($this->case, new \DateTimeImmutable('2026-03-02'));

        self::assertNull($this->service->dueReminderIndex($this->case, new \DateTimeImmutable('2026-03-04')));
    }

    /** A duty already paid has nothing left to chase. */
    public function testAPaidCaseIsNotChased(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->em->flush();

        self::assertSame(0, $this->service->run(new \DateTimeImmutable('2026-06-01')));
    }

    /** Nothing has been filed yet, so there is no risk to warn about. */
    public function testACaseNotYetFiledIsNotChased(): void
    {
        $this->case->setFiledAt(null);
        $this->em->flush();

        self::assertSame(0, $this->service->run(new \DateTimeImmutable('2026-06-01')));
    }

    /**
     * Rendered here rather than trusted: a template that throws is swallowed by the
     * dispatcher as a logged warning, so the in-app row would appear while the email
     * silently never went out.
     */
    public function testTheReminderEmailTemplateRenders(): void
    {
        $twig = static::getContainer()->get('twig');

        $html = $twig->render('emails/stamp_duty_unpaid.html.twig', [
            'case' => $this->case,
            'caseUrl' => 'http://localhost/case/1',
            'heading' => 'Heading',
            'body' => 'Body',
            'payUrl' => 'https://registratura.rejust.ro/',
            'courtCaseNumber' => '4521/302/2026',
        ]);

        self::assertStringContainsString('registratura.rejust.ro', $html);
        self::assertStringContainsString('4521/302/2026', $html);
    }

    public function testTheReminderReachesTheLawyerAsANotification(): void
    {
        $this->service->run(new \DateTimeImmutable('2026-03-04'));

        $notifications = $this->em->getRepository(Notification::class)->findBy([
            'user' => $this->user->getId(),
            'type' => NotificationType::STAMP_DUTY_UNPAID,
        ]);

        self::assertCount(1, $notifications);
    }
}
