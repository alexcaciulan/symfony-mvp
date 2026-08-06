<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlineType;
use App\Enum\StampDutyStatus;
use App\Service\Deadline\DeadlineAgendaItem;
use App\Service\Deadline\DeadlineCloseConfirmationResolver;
use App\Service\Deadline\DeadlineConsequenceResolver;
use App\Service\Deadline\DeadlineEstimateNote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the lawyer is told before closing a deadline.
 *
 * Two rules carry the feature. Only the terms whose miss cannot be undone get a
 * dialog, because a confirmation on every close is what teaches someone to click
 * through the one that mattered. And the stamp duty dialog states the duty as
 * recorded on the case, so closing it on an unpaid file is called what it is, a
 * declaration rather than a proof.
 */
class DeadlineCloseConfirmationResolverTest extends TestCase
{
    private DeadlineCloseConfirmationResolver $resolver;
    private DeadlineConsequenceResolver $consequenceResolver;

    protected function setUp(): void
    {
        $this->resolver = new DeadlineCloseConfirmationResolver();
        $this->consequenceResolver = new DeadlineConsequenceResolver();
    }

    /** @return iterable<string, array{DeadlineType, string}> */
    public static function fatalTypeProvider(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE, 'deadlines.confirm.stamp_duty.'];
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE, 'deadlines.confirm.forfeiture.'];
    }

    #[DataProvider('fatalTypeProvider')]
    public function testAnIrreversibleTermIsConfirmedWithItsOwnWording(DeadlineType $type, string $expectedPrefix): void
    {
        $confirmation = $this->resolver->resolve($this->item($type));

        self::assertNotNull($confirmation);
        self::assertSame($expectedPrefix . 'title', $confirmation->titleKey);
        self::assertSame($expectedPrefix . 'body', $confirmation->bodyKey);
        self::assertNotSame([], $confirmation->facts, 'The dialog states the record it is asking about.');
    }

    /**
     * @return iterable<string, array{DeadlineType}>
     */
    public static function reversibleTypeProvider(): iterable
    {
        yield 'hearing' => [DeadlineType::JUDECATA];
        yield 'summons answer' => [DeadlineType::RASPUNS_SOMATIE];
        yield 'free form reminder' => [DeadlineType::OTHER];
        // The three limitation terms are irreversible, yet they get no dialog: the
        // agenda offers no way to close them at all, so there is no close to confirm.
        // They are closed by the platform when the act that stops them happens, or from
        // the deadlines tab of the case, which does not go through this resolver.
        yield 'limitation' => [DeadlineType::PRESCRIPTIE];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE];
        yield 'filing the request' => [DeadlineType::DEPUNERE_CERERE];
    }

    #[DataProvider('reversibleTypeProvider')]
    public function testAReversibleTermIsClosedWithoutADialog(DeadlineType $type): void
    {
        self::assertNull($this->resolver->resolve($this->item($type)));
    }

    /** @return iterable<string, array{StampDutyStatus, bool}> */
    public static function stampDutyStatusProvider(): iterable
    {
        yield 'unpaid' => [StampDutyStatus::NEACHITATA, true];
        yield 'deferred to regularisation' => [StampDutyStatus::AMANATA_REGULARIZARE, true];
        yield 'refunded' => [StampDutyStatus::RESTITUITA, true];
        yield 'paid' => [StampDutyStatus::ACHITATA, false];
    }

    #[DataProvider('stampDutyStatusProvider')]
    public function testTheStampDutyDialogWarnsExactlyWhenTheRecordSaysItIsNotPaid(StampDutyStatus $status, bool $expectsWarning): void
    {
        $confirmation = $this->resolver->resolve($this->item(DeadlineType::TIMBRARE, stampDutyStatus: $status));

        self::assertNotNull($confirmation);
        self::assertSame(
            $expectsWarning ? 'deadlines.confirm.stamp_duty.warning_unpaid' : null,
            $confirmation->warningKey,
        );
    }

    public function testTheStampDutyDialogPrintsTheDutyAsStored(): void
    {
        $confirmation = $this->resolver->resolve($this->item(
            DeadlineType::TIMBRARE,
            stampDutyStatus: StampDutyStatus::ACHITATA,
            stampDuty: '200.00',
        ));

        self::assertNotNull($confirmation);
        $values = array_map(static fn (object $fact): string => $fact->value, $confirmation->facts);

        self::assertContains('200,00 RON', $values);
        self::assertContains('enum.stamp_duty_status.ACHITATA', $values);
    }

    /**
     * A case created before the amount was stored still owes the duty. Printing zero
     * there would state something the record does not say.
     */
    public function testAnAbsentStampDutyAmountIsWrittenAsUnknownRatherThanZero(): void
    {
        $confirmation = $this->resolver->resolve($this->item(DeadlineType::TIMBRARE, stampDuty: null));

        self::assertNotNull($confirmation);
        $values = array_map(static fn (object $fact): string => $fact->value, $confirmation->facts);

        self::assertContains('deadlines.confirm.fact.unknown', $values);
        self::assertNotContains('0,00 RON', $values);
    }

    /** Every fact is either a translation key or a formatted literal, never raw internals. */
    public function testFactsCarryTranslatableLabels(): void
    {
        $confirmation = $this->resolver->resolve($this->item(DeadlineType::CERERE_IN_ANULARE));

        self::assertNotNull($confirmation);
        foreach ($confirmation->facts as $fact) {
            self::assertStringStartsWith('deadlines.confirm.fact.', $fact->labelKey);
            self::assertNotSame('', $fact->value);
        }
    }

    /**
     * A type added to the enum must land on one side of the rule, never in between. The
     * rule is not "irreversible means confirmed" any more: it is "confirmed exactly for
     * the two terms the agenda still lets the lawyer close", the stamping term and the
     * annulment request.
     */
    public function testEveryTypeIsDecided(): void
    {
        $confirmed = [DeadlineType::TIMBRARE, DeadlineType::CERERE_IN_ANULARE];

        foreach (DeadlineType::cases() as $type) {
            $confirmation = $this->resolver->resolve($this->item($type));

            self::assertSame(
                in_array($type, $confirmed, true),
                $confirmation !== null,
                $type->value . ' must be confirmed if and only if the agenda offers a close for it.',
            );
        }
    }

    private function item(
        DeadlineType $type,
        StampDutyStatus $stampDutyStatus = StampDutyStatus::NEACHITATA,
        ?string $stampDuty = '200.00',
    ): DeadlineAgendaItem {
        $case = new LegalCase();
        $case->setCaseNumber('LR-2026-0001');
        $case->setStampDutyStatus($stampDutyStatus);
        $case->setStampDuty($stampDuty);

        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable('today +6 days'));

        return new DeadlineAgendaItem(
            deadline: $deadline,
            consequence: $this->consequenceResolver->resolve($type),
            certainty: DeadlineCertainty::CERT,
            daysRemaining: 6,
            estimateNote: new DeadlineEstimateNote('mark', 'note'),
        );
    }
}
