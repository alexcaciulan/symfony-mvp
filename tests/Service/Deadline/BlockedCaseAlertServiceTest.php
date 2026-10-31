<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\BlockedCaseAlert;
use App\Enum\CaseStatus;
use App\Enum\DeadlineConsequence;
use App\Enum\DeadlineType;
use App\Enum\PortalCaseMatchSource;
use App\Enum\StampDutyStatus;
use App\Event\BlockedCaseAlertEvent;
use App\Repository\LegalCaseRepository;
use App\Service\Deadline\BlockedCaseAlertService;
use App\Service\Deadline\DeadlineConsequenceResolver;
use App\Service\StampDuty\StampDutyPaymentWindow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The blockage zone of the agenda is passive: it lists the cases that need an act but
 * sends nothing. These alerts close that hole, and the whole risk of doing so is
 * turning a standing condition into daily noise, which is what the weekly key prevents.
 * The stamp duty is the deliberate exception: inside the payment window that opens
 * with the court file number the reminder repeats every day, because that window is
 * the only time the payment can still spare the petition.
 */
final class BlockedCaseAlertServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;
    private string $testPrefix;

    /** @var BlockedCaseAlertEvent[] */
    private array $dispatched = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testPrefix = 'blocked-case-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    /**
     * The registry takes the duty in the existing case from the moment the number
     * exists, and the court's notice to stamp, when it comes, may be served at the
     * client's seat: so the number is the moment the reminder starts, not the notice.
     */
    public function testACaseWithAFileNumberAndAnUnpaidDutyIsAlerted(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('800/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        $this->process();

        self::assertSame([BlockedCaseAlert::STAMP_DUTY_DUE], $this->reasonsFor($case));
    }

    /** No file number, none proposed either: nothing to pay into yet, so no reminder. */
    public function testACaseWithoutAFileNumberIsNotAlerted(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        $this->process();

        self::assertSame([], $this->reasonsFor($case));
    }

    public function testAPaidDutyRaisesNothing(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('801/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->em->flush();

        $this->process();

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * A duty deferred at filing is the same unpaid duty once the number exists: the
     * court does not care which button the lawyer pressed, so the reminder is the same.
     */
    public function testADeferredDutyIsChasedLikeAnUnpaidOne(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('802/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();
        $this->process();
        self::assertSame([BlockedCaseAlert::STAMP_DUTY_DUE], $this->reasonsFor($case));
    }

    /** While the stamping term is open and running, the ordinary deadline alerts cover the case. */
    public function testACaseThatAlreadyCarriesTheStampingTermIsNotAlerted(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('803/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->createStampDutyDeadline($case, date: new \DateTimeImmutable('2026-08-10'));
        $this->em->flush();

        $this->process();

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * Once the term the court granted has lapsed unstamped, the petition is annulled in
     * chambers: a reminder to pay would chase a payment that no longer saves anything.
     * The case moves to the blockage zone instead, where the outcome is recorded.
     */
    public function testAnExpiredOpenStampingTermSilencesTheAlert(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('810/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->createStampDutyDeadline($case, date: new \DateTimeImmutable('2026-08-02'));
        $this->em->flush();
        $this->process();
        self::assertSame([], $this->reasonsFor($case));
    }

    /** The term due on the run date itself is still running. */
    public function testAStampingTermDueOnTheRunDateStillSilencesTheAlert(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('811/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->createStampDutyDeadline($case, date: new \DateTimeImmutable('2026-08-03'));
        $this->em->flush();

        $this->process(new \DateTimeImmutable('2026-08-03 14:30'));

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * Closing the term says nothing about payment: while the duty reads unpaid the card
     * and the petition treat it as unpaid, so the alert keeps asking for the payment.
     */
    public function testAClosedStampingTermDoesNotSilenceTheAlertWhileTheDutyReadsUnpaid(): void
    {
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);
        $case->setCourtCaseNumber('812/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->createStampDutyDeadline($case, completed: true, date: new \DateTimeImmutable('2026-08-10'));
        $this->em->flush();

        $this->process();

        self::assertSame([BlockedCaseAlert::STAMP_DUTY_DUE], $this->reasonsFor($case));
    }

    /**
     * After the order is issued, a duty still reading unpaid is almost always a payment
     * that was never recorded; the reminder keeps asking for the record, weekly by then.
     */
    public function testACaseInOrdonantaEmisaWithUnpaidDutyIsAlerted(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setCourtCaseNumber('813/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        $this->process();

        self::assertSame([BlockedCaseAlert::STAMP_DUTY_DUE], $this->reasonsFor($case));
    }

    public function testACaseInAnulareWithUnpaidDutyIsAlerted(): void
    {
        $case = $this->createCase(CaseStatus::IN_ANULARE);
        $case->setCourtCaseNumber('814/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        $this->process();

        self::assertSame([BlockedCaseAlert::STAMP_DUTY_DUE], $this->reasonsFor($case));
    }

    /** Once the order is final the annulment sanction is no longer operable, and terminal cases are done. */
    public function testDefinitivaExecutareAndTerminalCasesAreNotAlertedForStampDuty(): void
    {
        $cases = [];
        foreach ([CaseStatus::DEFINITIVA, CaseStatus::EXECUTARE, CaseStatus::RESPINSA, CaseStatus::INCHIS_SUCCES] as $i => $status) {
            $case = $this->createCase($status);
            $case->setCourtCaseNumber((820 + $i) . '/211/2026');
            $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
            $cases[$status->name] = $case;
        }
        $this->em->flush();

        $this->process();

        foreach ($cases as $name => $case) {
            self::assertNotContains(BlockedCaseAlert::STAMP_DUTY_DUE, $this->reasonsFor($case), $name);
        }
    }

    /** A term closed by hand on a deferred duty proves no payment either, so it hides nothing. */
    public function testADeferredCaseWithAClosedStampingTermIsStillChased(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('815/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->createStampDutyDeadline($case, completed: true, date: new \DateTimeImmutable('2026-07-20'));
        $this->em->flush();
        $this->process();
        self::assertSame([BlockedCaseAlert::STAMP_DUTY_DUE], $this->reasonsFor($case));
    }

    /**
     * The portal search found a court case that looks like this one and the lawyer has
     * not answered. The court does not wait for that answer, so the reminder does not
     * either: it asks for the check and the payment together, under its own wording.
     */
    public function testAProposedNumberNotYetConfirmedIsChasedUnderItsOwnWording(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        self::assertTrue($case->proposePortalMatch('900/211/2026', PortalCaseMatchSource::AUTO));
        $this->em->flush();
        $this->process();
        self::assertSame([BlockedCaseAlert::STAMP_DUTY_DUE_UNCONFIRMED], $this->reasonsFor($case));
    }

    /** Setting the proposal aside ends the chase: nothing points at a number any more. */
    public function testADismissedProposalIsNotChased(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $case->proposePortalMatch('901/211/2026', PortalCaseMatchSource::AUTO);
        $case->dismissPortalProposal(null);
        $this->em->flush();
        $this->process();
        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * What the file number triggers is a REMINDER, and a reminder only. The duty is due
     * from that moment (OUG 80/2013 art. 33 para. 1), but the ten days whose lapse annuls
     * the claim (para. 2, CPC art. 197) run from the court's notice, which nobody has
     * sent yet. So the case is told to pay and carries no term at all: a TIMBRARE
     * deadline here would show a date no document supports, and the consequence attached
     * to that type is the annulment of the claim.
     */
    public function testTheFileNumberProducesAReminderAndNoTermWithAnAnnulmentConsequence(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('806/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        // A due date, so the case carries the terms it really would carry at this point
        // (the limitation of NCC art. 2517) and the check below reads a populated list
        // rather than an empty one.
        $case->setDueDate(new \DateTime('2025-03-10'));
        $this->em->flush();

        $this->process();

        self::assertSame([BlockedCaseAlert::STAMP_DUTY_DUE], $this->reasonsFor($case));

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $case->getId()]);
        self::assertNotSame([], $deadlines);

        $consequences = new DeadlineConsequenceResolver();
        foreach ($deadlines as $deadline) {
            self::assertNotSame(
                DeadlineConsequence::CASE_ANNULMENT,
                $consequences->resolve($deadline->getType()),
                $deadline->getType()->value . ' would make a reminder look like a fatal term.',
            );
        }
    }

    /**
     * Inside the payment window the key carries the day: the job runs every morning and
     * every morning delivers, which is the pressure the window exists to apply.
     */
    public function testInsideThePaymentWindowTheKeyChangesEveryDay(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('804/211/2026');
        $case->setCourtCaseNumberKnownAt(new \DateTimeImmutable('2026-08-01 10:00'));
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();
        $this->process(new \DateTimeImmutable('2026-08-03'));
        $this->process(new \DateTimeImmutable('2026-08-04'));
        $this->process(new \DateTimeImmutable('2026-08-06')); // last day of the window
        $keys = array_map(static fn (BlockedCaseAlertEvent $e): ?string => $e->dedupKey, $this->eventsFor($case));
        self::assertCount(3, $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'Each day of the window is a new message.');
        self::assertStringEndsWith(':2026-08-03', (string) $keys[0]);
    }

    /**
     * After the window the key falls back to the ISO week: every day of the same week
     * produces the same one, so the second and later runs inside a week deliver nothing.
     */
    public function testAfterThePaymentWindowTheKeyIsStableAcrossTheWholeWeekAndChangesWithIt(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('805/211/2026');
        $case->setCourtCaseNumberKnownAt(new \DateTimeImmutable('2026-07-20 10:00'));
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();
        $this->process(new \DateTimeImmutable('2026-08-03')); // Monday
        $this->process(new \DateTimeImmutable('2026-08-07')); // Friday, same week
        $this->process(new \DateTimeImmutable('2026-08-10')); // the Monday after
        $keys = array_map(static fn (BlockedCaseAlertEvent $e): ?string => $e->dedupKey, $this->eventsFor($case));
        self::assertCount(3, $keys);
        self::assertSame($keys[0], $keys[1], 'Two runs in the same week must carry the same key.');
        self::assertNotSame($keys[1], $keys[2], 'A new week is a new message.');
        self::assertStringContainsString((string) $case->getId(), (string) $keys[0]);
    }

    /** The day after the window the key is already the weekly one. */
    public function testTheDayAfterThePaymentWindowFallsBackToTheWeeklyKey(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('808/211/2026');
        $case->setCourtCaseNumberKnownAt(new \DateTimeImmutable('2026-08-01 10:00'));
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        $this->process(new \DateTimeImmutable('2026-08-06')); // last day of the window: daily
        $this->process(new \DateTimeImmutable('2026-08-07')); // first day after: weekly

        $keys = array_map(static fn (BlockedCaseAlertEvent $e): ?string => $e->dedupKey, $this->eventsFor($case));
        self::assertStringEndsWith(':2026-08-06', (string) $keys[0]);
        self::assertStringEndsWith(':2026-W32', (string) $keys[1]);
    }

    /** A case that never recorded when its number appeared keeps the weekly pace rather than an invented window. */
    public function testWithoutTheDateTheNumberAppearedTheKeyIsWeekly(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('807/211/2026');
        $case->setCourtCaseNumberKnownAt(null);
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();
        $this->process(new \DateTimeImmutable('2026-08-03'));
        $this->process(new \DateTimeImmutable('2026-08-04'));
        $keys = array_map(static fn (BlockedCaseAlertEvent $e): ?string => $e->dedupKey, $this->eventsFor($case));
        self::assertSame($keys[0], $keys[1]);
    }

    public function testTheReportCountsEachReasonSeparately(): void
    {
        $due = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $due->setCourtCaseNumber('805/211/2026');
        $due->setStampDutyStatus(StampDutyStatus::NEACHITATA);

        $proposed = $this->createCase(CaseStatus::CERERE_DEPUSA);
        $proposed->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $proposed->proposePortalMatch('902/211/2026', PortalCaseMatchSource::AUTO);

        $enforcement = $this->createCase(CaseStatus::EXECUTARE);
        $enforcement->setEnforcementRequestDate(new \DateTimeImmutable('2026-07-01'));
        $this->em->flush();

        $report = $this->process();

        self::assertGreaterThanOrEqual(1, $report->stampDutyDue);
        self::assertGreaterThanOrEqual(1, $report->stampDutyDueUnconfirmed);
        self::assertGreaterThanOrEqual(1, $report->enforcementRegistrationNumberMissing);
        self::assertSame(
            $report->stampDutyDue + $report->stampDutyDueUnconfirmed + $report->enforcementRegistrationNumberMissing,
            $report->total(),
        );
    }

    /**
     * The bailiff registers the request on receipt, so the grace covers the round trip of
     * the confirmation and nothing more. Before it runs out the case is only carried by
     * the blockage zone, which is passive.
     */
    public function testTheRegistrationNumberIsNotChasedInsideTheGracePeriod(): void
    {
        $case = $this->createCase(CaseStatus::EXECUTARE);
        $case->setEnforcementRequestDate(new \DateTimeImmutable('2026-08-01'));
        $this->em->flush();

        $this->process(new \DateTimeImmutable('2026-08-05'));

        self::assertSame([], $this->reasonsFor($case));
    }

    public function testTheRegistrationNumberIsChasedOnceTheGracePeriodHasRun(): void
    {
        $case = $this->createCase(CaseStatus::EXECUTARE);
        $case->setEnforcementRequestDate(new \DateTimeImmutable('2026-08-01'));
        $this->em->flush();

        $this->process(new \DateTimeImmutable('2026-08-20'));

        self::assertSame([BlockedCaseAlert::ENFORCEMENT_REGISTRATION_NUMBER_MISSING], $this->reasonsFor($case));
    }

    /** A number already recorded means the term is closed; there is nothing to chase. */
    public function testACaseThatAlreadyCarriesTheRegistrationNumberIsNotChased(): void
    {
        $case = $this->createCase(CaseStatus::EXECUTARE);
        $case->setEnforcementRequestDate(new \DateTimeImmutable('2026-08-01'));
        $case->setEnforcementRegistrationNumber('412/2026');
        $this->em->flush();

        $this->process(new \DateTimeImmutable('2026-08-20'));

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * The reminder waits on a bailiff, not on the lawyer, and the term it guards runs for
     * three years. So its key carries no period at all: every later run rebuilds the same
     * key and the dispatcher refuses the delivery, which is exactly one message ever.
     */
    public function testTheRegistrationNumberReminderCarriesAKeyThatNeverChanges(): void
    {
        $case = $this->createCase(CaseStatus::EXECUTARE);
        $case->setEnforcementRequestDate(new \DateTimeImmutable('2026-08-01'));
        $this->em->flush();

        $this->process(new \DateTimeImmutable('2026-08-20'));
        $this->process(new \DateTimeImmutable('2026-09-28'));
        $this->process(new \DateTimeImmutable('2027-04-05'));

        $keys = array_map(static fn (BlockedCaseAlertEvent $e): ?string => $e->dedupKey, $this->eventsFor($case));

        self::assertCount(3, $keys);
        self::assertSame([$keys[0]], array_values(array_unique($keys)), 'One key means one delivered message, ever.');
    }

    private function process(?\DateTimeImmutable $now = null): \App\Service\Deadline\BlockedCaseAlertReport
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            if ($event instanceof BlockedCaseAlertEvent) {
                $this->dispatched[] = $event;
            }

            return $event;
        });

        $service = new BlockedCaseAlertService(
            static::getContainer()->get(LegalCaseRepository::class),
            $dispatcher,
            new StampDutyPaymentWindow(),
            enforcementRegistrationGraceDays: 10,
        );

        return $service->process($now ?? new \DateTimeImmutable('2026-08-03'));
    }

    /** @return BlockedCaseAlertEvent[] */
    private function eventsFor(LegalCase $case): array
    {
        return array_values(array_filter(
            $this->dispatched,
            static fn (BlockedCaseAlertEvent $e): bool => $e->case->getId() === $case->getId(),
        ));
    }

    /** @return list<BlockedCaseAlert> */
    private function reasonsFor(LegalCase $case): array
    {
        return array_map(static fn (BlockedCaseAlertEvent $e): BlockedCaseAlert => $e->reason, $this->eventsFor($case));
    }

    private function createCase(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $this->em->persist($case);

        return $case;
    }

    private function createStampDutyDeadline(LegalCase $case, bool $completed = false, ?\DateTimeImmutable $date = null): void
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType(DeadlineType::TIMBRARE);
        $deadline->setDeadlineDate($date ?? new \DateTimeImmutable('2026-08-10'));
        $deadline->setCompleted($completed);
        $deadline->setPriority(DeadlineType::TIMBRARE->defaultPriority());
        $this->em->persist($deadline);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE pm FROM portal_case_match pm JOIN legal_case lc ON pm.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);

        parent::tearDown();
    }
}
