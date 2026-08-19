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
use App\Enum\StampDutyStatus;
use App\Event\BlockedCaseAlertEvent;
use App\Repository\LegalCaseRepository;
use App\Service\Deadline\BlockedCaseAlertService;
use App\Service\Deadline\DeadlineConsequenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The blockage zone of the agenda is passive: it lists the cases that need an act but
 * sends nothing. These alerts close that hole, and the whole risk of doing so is
 * turning a standing condition into daily noise, which is what the weekly key prevents.
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
     * The duty is paid in advance (OUG 80/2013 art. 33 para. 1), so the file number
     * appearing on the portal is the moment it is due. Waiting for a regularization
     * notice is waiting for the court to ask.
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

    /** No file number means the claim is not registered yet, so the duty is not due. */
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
     * A duty deferred pending regularization is the other trigger, and only that one:
     * the two are disjoint on `stampDutyStatus`, so a case never produces two messages
     * about the same duty.
     */
    public function testADeferredDutyAsksForTheNoticeDateInstead(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('802/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();

        $this->process();

        self::assertSame([BlockedCaseAlert::REGULARIZATION_NOTICE_DATE_MISSING], $this->reasonsFor($case));
    }

    /** Once the stamping term exists, the ordinary deadline alerts cover the case. */
    public function testACaseThatAlreadyCarriesTheStampingTermIsNotAlerted(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('803/211/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->createStampDutyDeadline($case);
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
     * The key is what throttles delivery downstream: every day of the same ISO week
     * produces the same one, so the second and later runs inside a week deliver nothing.
     */
    public function testTheDedupKeyIsStableAcrossTheWholeWeekAndChangesWithIt(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('804/211/2026');
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

    public function testTheReportCountsEachReasonSeparately(): void
    {
        $due = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $due->setCourtCaseNumber('805/211/2026');
        $due->setStampDutyStatus(StampDutyStatus::NEACHITATA);

        $deferred = $this->createCase(CaseStatus::TERMEN_FIXAT);
        $deferred->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);

        $enforcement = $this->createCase(CaseStatus::EXECUTARE);
        $enforcement->setEnforcementRequestDate(new \DateTimeImmutable('2026-07-01'));
        $this->em->flush();

        $report = $this->process();

        self::assertGreaterThanOrEqual(1, $report->stampDutyDue);
        self::assertGreaterThanOrEqual(1, $report->regularizationNoticeDateMissing);
        self::assertGreaterThanOrEqual(1, $report->enforcementRegistrationNumberMissing);
        self::assertSame(
            $report->stampDutyDue + $report->regularizationNoticeDateMissing + $report->enforcementRegistrationNumberMissing,
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

    private function createStampDutyDeadline(LegalCase $case): void
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType(DeadlineType::TIMBRARE);
        $deadline->setDeadlineDate(new \DateTimeImmutable('+7 days'));
        $deadline->setPriority(DeadlineType::TIMBRARE->defaultPriority());
        $this->em->persist($deadline);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);

        parent::tearDown();
    }
}
