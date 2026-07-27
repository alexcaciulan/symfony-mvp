<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlineType;
use App\Service\Deadline\DeadlineAgendaItem;
use App\Service\Deadline\DeadlineCertaintyResolver;
use App\Service\Deadline\DeadlineConsequenceResolver;
use App\Service\Deadline\DeadlineRowActionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the buttons of an agenda row. The rules under test are the ones a template
 * must never be allowed to reinvent: a row has at most one close button, the close
 * of a limitation term is worded as ending the tracking, and a fatal term already
 * missed on a certain date offers no close at all.
 */
class DeadlineRowActionResolverTest extends TestCase
{
    private DeadlineRowActionResolver $resolver;
    private DeadlineConsequenceResolver $consequenceResolver;

    protected function setUp(): void
    {
        $this->resolver = new DeadlineRowActionResolver();
        $this->consequenceResolver = new DeadlineConsequenceResolver();
    }

    /** @return iterable<string, array{DeadlineType, string}> */
    public static function actOnTheSpotProvider(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE, 'deadlines.action.mark_stamped'];
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE, 'deadlines.action.mark_done'];
        yield 'free form reminder' => [DeadlineType::OTHER, 'deadlines.action.mark_done'];
    }

    /** When the act is performed here, the primary button is the close itself. */
    #[DataProvider('actOnTheSpotProvider')]
    public function testActPerformedOnTheSpotHasTheCloseAsItsOnlyButton(DeadlineType $type, string $expectedLabel): void
    {
        $action = $this->resolver->resolve($this->item($type, daysRemaining: 3));

        self::assertSame($expectedLabel, $action->primary->label);
        self::assertTrue($action->primary->closesDeadline);
        self::assertSame('case_deadline_complete', $action->primary->route);
        self::assertNull($action->close, 'A row must never carry two close controls.');
    }

    public function testHearingLeadsToThePortalAndKeepsTheCloseAsSecondary(): void
    {
        $action = $this->resolver->resolve($this->item(DeadlineType::JUDECATA, daysRemaining: 1));

        self::assertSame('deadlines.action.open_portal', $action->primary->label);
        self::assertSame('case_overview', $action->primary->route);
        self::assertSame('portal', $action->primary->routeParameters['tab']);
        self::assertFalse($action->primary->closesDeadline);
        self::assertNotNull($action->close);
        self::assertSame('deadlines.action.mark_done', $action->close->label);
        self::assertTrue($action->close->closesDeadline);
    }

    public function testEstimatedSummonsAnswerAsksForTheCommunicationDateFirst(): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::RASPUNS_SOMATIE, daysRemaining: 5, certainty: DeadlineCertainty::ESTIMAT),
        );

        self::assertSame('deadlines.action.set_summons_communication_date', $action->primary->label);
        self::assertSame('case_deadline_summons_communication_date', $action->primary->route);
        self::assertNotNull($action->close);
    }

    public function testExpiredSummonsAnswerOffersFilingThePaymentOrder(): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::RASPUNS_SOMATIE, daysRemaining: -2, certainty: DeadlineCertainty::CERT),
        );

        self::assertSame('deadlines.action.generate_payment_order', $action->primary->label);
        self::assertSame('case_payment_order_generate', $action->primary->route);
        self::assertNotNull($action->close);
    }

    public function testRunningSummonsAnswerOnlyOffersTheClose(): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::RASPUNS_SOMATIE, daysRemaining: 4, certainty: DeadlineCertainty::CERT),
        );

        self::assertTrue($action->primary->closesDeadline);
        self::assertNull($action->close);
    }

    /** @return iterable<string, array{CaseStatus, string}> */
    public static function limitationStageProvider(): iterable
    {
        yield 'before the summons' => [CaseStatus::AMIABIL, 'deadlines.action.send_summons'];
        yield 'summons sent' => [CaseStatus::SOMATIE_TRIMISA, 'deadlines.action.generate_payment_order'];
        yield 'already filed' => [CaseStatus::DOSAR_INREGISTRAT, 'deadlines.action.open_case'];
    }

    #[DataProvider('limitationStageProvider')]
    public function testLimitationOffersTheActThatStopsItAtEachStage(CaseStatus $status, string $expectedLabel): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::PRESCRIPTIE, daysRemaining: 12, status: $status),
        );

        self::assertSame($expectedLabel, $action->primary->label);
        self::assertNotNull($action->close);
        self::assertSame('deadlines.action.stop_tracking', $action->close->label, 'A limitation term is never "done".');
        self::assertSame('deadlines.action.note.stop_tracking', $action->close->note);
    }

    public function testEnforcementLimitationIsNeverLabelledAsDone(): void
    {
        $action = $this->resolver->resolve($this->item(DeadlineType::PRESCRIPTIE_EXECUTARE, daysRemaining: 20));

        self::assertSame('deadlines.action.open_case', $action->primary->label);
        self::assertNotNull($action->close);
        self::assertSame('deadlines.action.stop_tracking', $action->close->label);
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function fatalTypeProvider(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE];
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE];
        yield 'limitation' => [DeadlineType::PRESCRIPTIE];
    }

    #[DataProvider('fatalTypeProvider')]
    public function testMissedFatalDeadlineOnACertainDateLosesItsCloseButton(DeadlineType $type): void
    {
        $action = $this->resolver->resolve(
            $this->item($type, daysRemaining: -4, certainty: DeadlineCertainty::CERT),
        );

        self::assertSame('deadlines.action.open_case', $action->primary->label);
        self::assertSame('case_overview', $action->primary->route);
        self::assertFalse($action->hasCloseButton(), 'A consumed fatal term must not be silenceable.');
    }

    /**
     * An estimated date says nothing about the consequence, so the row stays
     * workable even past its date. This is the limitation term on a case whose
     * summons was communicated: the interruption is not modelled.
     */
    public function testMissedFatalDeadlineOnAnEstimatedDateKeepsItsActions(): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::PRESCRIPTIE, daysRemaining: -9, certainty: DeadlineCertainty::ESTIMAT, status: CaseStatus::SOMATIE_TRIMISA),
        );

        self::assertSame('deadlines.action.generate_payment_order', $action->primary->label);
        self::assertTrue($action->hasCloseButton());
    }

    public function testEveryTypeResolvesToAnAction(): void
    {
        foreach (DeadlineType::cases() as $type) {
            $action = $this->resolver->resolve($this->item($type, daysRemaining: 2));
            self::assertNotSame('', $action->primary->label, $type->value . ' must resolve to a primary action.');
        }
    }

    /**
     * The whole mapping in one place, one line per deadline type, read at a neutral
     * position: the date is certain and still ahead, so nothing is consumed yet. The
     * third column says whether the close is a button of its own, which is exactly
     * the question "is the primary act the closing of this term".
     *
     * @return iterable<string, array{DeadlineType, string, bool}>
     */
    public static function primaryActionPerTypeProvider(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE, 'deadlines.action.mark_stamped', false];
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE, 'deadlines.action.mark_done', false];
        yield 'free form reminder' => [DeadlineType::OTHER, 'deadlines.action.mark_done', false];
        yield 'summons answer' => [DeadlineType::RASPUNS_SOMATIE, 'deadlines.action.mark_done', false];
        yield 'hearing' => [DeadlineType::JUDECATA, 'deadlines.action.open_portal', true];
        // The case is at SOMATIE_TRIMISA, so what stops the limitation period is the
        // request filed in court (CPC art. 1015 para. 2).
        yield 'limitation' => [DeadlineType::PRESCRIPTIE, 'deadlines.action.generate_payment_order', true];
        // Satisfied by the same act as the limitation term, the request reaching the
        // court within the six months of NCC art. 2540, so it offers the same button.
        yield 'filing the request' => [DeadlineType::DEPUNERE_CERERE, 'deadlines.action.generate_payment_order', true];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE, 'deadlines.action.open_case', true];
    }

    #[DataProvider('primaryActionPerTypeProvider')]
    public function testPrimaryActionPerType(DeadlineType $type, string $expectedLabel, bool $expectsSeparateClose): void
    {
        $action = $this->resolver->resolve($this->item($type, daysRemaining: 6));

        self::assertSame($expectedLabel, $action->primary->label);
        self::assertSame($expectsSeparateClose, $action->close !== null);
        self::assertSame(!$expectsSeparateClose, $action->primary->closesDeadline);
        self::assertTrue($action->hasCloseButton(), 'A term still running is always closeable, one way or the other.');

        // Whichever side the close lands on, it posts to the route the case page has
        // always used, carrying the token that route validates. Nothing on this page
        // closes a deadline any other way.
        $close = $action->close ?? $action->primary;
        self::assertSame('case_deadline_complete', $close->route);
        self::assertSame('POST', $close->method);
        self::assertNotNull($close->csrfTokenId);
        self::assertArrayHasKey('deadlineId', $close->routeParameters);
    }

    /** A type added to the enum without a line above would silently go untested. */
    public function testEveryTypeIsPinnedByTheMappingAbove(): void
    {
        $covered = array_map(
            static fn (array $row): DeadlineType => $row[0],
            iterator_to_array(self::primaryActionPerTypeProvider()),
        );

        self::assertEqualsCanonicalizing(DeadlineType::cases(), array_values($covered));
    }


    private function item(
        DeadlineType $type,
        int $daysRemaining,
        DeadlineCertainty $certainty = DeadlineCertainty::CERT,
        CaseStatus $status = CaseStatus::SOMATIE_TRIMISA,
    ): DeadlineAgendaItem {
        $case = new LegalCase();
        $case->setStatus($status);

        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable('today ' . $daysRemaining . ' days'));

        return new DeadlineAgendaItem(
            deadline: $deadline,
            consequence: $this->consequenceResolver->resolve($type),
            certainty: $certainty,
            daysRemaining: $daysRemaining,
            estimateReasonKey: (new DeadlineCertaintyResolver())->estimateReasonKey($type),
        );
    }
}
