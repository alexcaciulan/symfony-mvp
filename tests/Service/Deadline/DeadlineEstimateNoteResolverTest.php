<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Service\Deadline\DeadlineEstimateNote;
use App\Service\Deadline\DeadlineEstimateNoteResolver;
use App\Service\Deadline\DeadlineService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The marker under a date. On a limitation term it states the interruption, and the
 * dangerous form is the one a clock decides: past the six months with nothing filed, the
 * interruption is deemed never to have happened (NCC art. 2540), and a row still saying
 * "holds until" would read as a protection that no longer exists.
 *
 * A kernel test because the date it states has to be the very arithmetic the six-month
 * term is stored with.
 */
final class DeadlineEstimateNoteResolverTest extends KernelTestCase
{
    private const COMMUNICATED_ON = '2026-03-02';

    private DeadlineService $deadlineService;
    private \DateTimeImmutable $holdsUntil;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->deadlineService = static::getContainer()->get(DeadlineService::class);
        $this->holdsUntil = $this->deadlineService->filingInterruptionTermEnd(new \DateTimeImmutable(self::COMMUNICATED_ON))->end;
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function typesOtherThanTheLimitationProvider(): iterable
    {
        foreach (DeadlineType::cases() as $type) {
            if ($type !== DeadlineType::PRESCRIPTIE) {
                yield $type->value => [$type];
            }
        }
    }

    #[DataProvider('typesOtherThanTheLimitationProvider')]
    public function testEveryOtherTypeSaysTheFactIsNotConfirmed(DeadlineType $type): void
    {
        // A communication date on the case changes nothing outside the limitation term.
        $note = $this->resolver()->resolve($type, $this->communicatedCase());

        self::assertSame('deadlines.row.estimate.unconfirmed_fact.mark', $note->markKey);
        self::assertSame('deadlines.row.estimate.unconfirmed_fact.note', $note->noteKey);
        self::assertSame([], $note->parameters);
    }

    public function testALimitationTermWithoutACommunicationFallsBackToTheUnconfirmedFact(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::SOMATIE_TRIMISA);

        $note = $this->resolver()->resolve(DeadlineType::PRESCRIPTIE, $case);

        self::assertSame('deadlines.row.estimate.unconfirmed_fact.mark', $note->markKey);
    }

    public function testBeforeTheFilingTheNoteStatesUntilWhenTheInterruptionHolds(): void
    {
        $note = $this->resolver($this->holdsUntil->modify('-30 days'))->resolve(DeadlineType::PRESCRIPTIE, $this->communicatedCase());

        $this->assertInterruptedBySummons($note);
    }

    public function testOnItsLastDayTheInterruptionStillHolds(): void
    {
        $note = $this->resolver($this->holdsUntil)->resolve(DeadlineType::PRESCRIPTIE, $this->communicatedCase());

        $this->assertInterruptedBySummons($note);
    }

    /** The time of day must not end a term that runs through its whole last day. */
    public function testLateOnItsLastDayTheInterruptionStillHolds(): void
    {
        $note = $this->resolver($this->holdsUntil->setTime(23, 59, 59))->resolve(DeadlineType::PRESCRIPTIE, $this->communicatedCase());

        $this->assertInterruptedBySummons($note);
    }

    public function testTheDayAfterTheInterruptionHasLapsed(): void
    {
        $note = $this->resolver($this->holdsUntil->modify('+1 day'))->resolve(DeadlineType::PRESCRIPTIE, $this->communicatedCase());

        self::assertSame('deadlines.row.estimate.prescription_interruption_lapsed.mark', $note->markKey);
        self::assertSame('deadlines.row.estimate.prescription_interruption_lapsed.note', $note->noteKey);
        self::assertSame([], $note->parameters, 'A lapsed interruption has no date left to state.');
    }

    /** @return iterable<string, array{CaseStatus}> */
    public static function statusesBeforeTheFilingProvider(): iterable
    {
        yield 'summons sent' => [CaseStatus::SOMATIE_TRIMISA];
        yield 'request generated' => [CaseStatus::CERERE_GENERATA];
        yield 'amicable' => [CaseStatus::AMIABIL];
    }

    #[DataProvider('statusesBeforeTheFilingProvider')]
    public function testEveryStatusBeforeTheFilingCanLapse(CaseStatus $status): void
    {
        $note = $this->resolver($this->holdsUntil->modify('+1 day'))->resolve(DeadlineType::PRESCRIPTIE, $this->communicatedCase($status));

        self::assertSame('deadlines.row.estimate.prescription_interruption_lapsed.mark', $note->markKey);
    }

    /** @return iterable<string, array{CaseStatus}> */
    public static function statusesAfterTheFilingProvider(): iterable
    {
        yield 'filed' => [CaseStatus::CERERE_DEPUSA];
        yield 'registered' => [CaseStatus::DOSAR_INREGISTRAT];
        yield 'order issued' => [CaseStatus::ORDONANTA_EMISA];
    }

    /** Once filed, the six months are over: the filing interrupts on its own (NCC art. 2537). */
    #[DataProvider('statusesAfterTheFilingProvider')]
    public function testAfterTheFilingTheNoteNamesTheFiling(CaseStatus $status): void
    {
        $note = $this->resolver($this->holdsUntil->modify('-30 days'))->resolve(DeadlineType::PRESCRIPTIE, $this->communicatedCase($status));

        self::assertSame('deadlines.row.estimate.prescription_interrupted_by_filing.mark', $note->markKey);
        self::assertSame('deadlines.row.estimate.prescription_interrupted_by_filing.note', $note->noteKey);
        self::assertSame([], $note->parameters);
    }

    /** A filed case cannot lapse, however far past the six months the clock is. */
    #[DataProvider('statusesAfterTheFilingProvider')]
    public function testAFiledCaseNeverReadsAsLapsed(CaseStatus $status): void
    {
        $note = $this->resolver($this->holdsUntil->modify('+200 days'))->resolve(DeadlineType::PRESCRIPTIE, $this->communicatedCase($status));

        self::assertSame('deadlines.row.estimate.prescription_interrupted_by_filing.mark', $note->markKey);
    }

    public function testResolveForReadsTheTypeAndTheCaseOffTheDeadline(): void
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($this->communicatedCase());
        $deadline->setType(DeadlineType::PRESCRIPTIE);
        $deadline->setDeadlineDate(new \DateTimeImmutable('2027-01-01'));

        $this->assertInterruptedBySummons($this->resolver($this->holdsUntil->modify('-30 days'))->resolveFor($deadline));
    }

    /** The note speaks for the whole row, so it waits for the last term it stands for. */
    public function testAMergedRowClaimsNothingLostWhileItsLastTermOutlivedTheSummons(): void
    {
        $deadline = (new LegalDeadline())
            ->setType(DeadlineType::PRESCRIPTIE)
            ->setLegalCase($this->communicatedCase())
            ->setDeadlineDate(new \DateTimeImmutable('2026-01-10'));

        $note = $this->resolver()->resolveFor($deadline, new \DateTimeImmutable('2026-04-10'));

        self::assertStringNotContainsString('ran_out_before_summons', $note->markKey);
    }

    /** Filed past the six months and past the due date: neither act interrupts it. */
    public function testATermThatRanOutBeforeALateFilingClaimsNoInterruption(): void
    {
        $case = $this->communicatedCase(CaseStatus::CERERE_DEPUSA);
        $filedAt = $this->holdsUntil->modify('+10 days');
        $case->setFiledAt($filedAt);

        $ranOut = (new LegalDeadline())
            ->setType(DeadlineType::PRESCRIPTIE)
            ->setLegalCase($case)
            ->setDeadlineDate($filedAt->modify('-1 day'));
        $running = (new LegalDeadline())
            ->setType(DeadlineType::PRESCRIPTIE)
            ->setLegalCase($case)
            ->setDeadlineDate($filedAt->modify('+1 day'));

        self::assertSame('deadlines.row.estimate.prescription_ran_out_before_late_filing.mark', $this->resolver()->resolveFor($ranOut)->markKey);
        self::assertSame('deadlines.row.estimate.prescription_interrupted_by_filing.mark', $this->resolver()->resolveFor($running)->markKey);
    }

    public function testATermThatRanOutBeforeTheSummonsClaimsNoInterruption(): void
    {
        $case = $this->communicatedCase();
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType(DeadlineType::PRESCRIPTIE);
        $deadline->setDeadlineDate((new \DateTimeImmutable(self::COMMUNICATED_ON))->modify('-1 day'));

        self::assertSame(
            'deadlines.row.estimate.prescription_ran_out_before_summons.mark',
            $this->resolver()->resolveFor($deadline)->markKey,
        );
    }

    private function assertInterruptedBySummons(DeadlineEstimateNote $note): void
    {
        self::assertSame('deadlines.row.estimate.prescription_interrupted.mark', $note->markKey);
        self::assertSame('deadlines.row.estimate.prescription_interrupted.note', $note->noteKey);
        self::assertSame(['%date%' => $this->holdsUntil->format('d.m.Y')], $note->parameters);
    }

    private function resolver(?\DateTimeImmutable $now = null): DeadlineEstimateNoteResolver
    {
        return new DeadlineEstimateNoteResolver($this->deadlineService, new MockClock($now ?? new \DateTimeImmutable(self::COMMUNICATED_ON)));
    }

    private function communicatedCase(CaseStatus $status = CaseStatus::SOMATIE_TRIMISA): LegalCase
    {
        $case = new LegalCase();
        $case->setStatus($status);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable(self::COMMUNICATED_ON));

        return $case;
    }
}
