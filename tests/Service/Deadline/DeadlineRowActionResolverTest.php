<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlineType;
use App\Service\Deadline\DeadlineAgendaItem;
use App\Service\Deadline\DeadlineConsequenceResolver;
use App\Service\Deadline\DeadlineEstimateNote;
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
    private const CLOSE_PRIMARY = 'primary';
    private const CLOSE_SECONDARY = 'secondary';
    private const CLOSE_NONE = 'none';

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
        yield 'stamp duty' => [DeadlineType::TIMBRARE, 'deadlines.action.mark_stamped', 'case_deadline_complete'];
        yield 'free form reminder' => [DeadlineType::OTHER, 'deadlines.action.mark_done', 'case_deadline_complete'];
        // The platform cannot draft the annulment request, so the only act it offers on
        // this window is the decision not to use it, on a route of its own.
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE, 'deadlines.action.no_annulment_request', 'case_deadline_no_annulment_request'];
    }

    /** When the act is performed here, the primary button is the close itself. */
    #[DataProvider('actOnTheSpotProvider')]
    public function testActPerformedOnTheSpotHasTheCloseAsItsOnlyButton(DeadlineType $type, string $expectedLabel, string $expectedRoute): void
    {
        $action = $this->resolver->resolve($this->item($type, daysRemaining: 3));

        self::assertSame($expectedLabel, $action->primary->label);
        self::assertTrue($action->primary->closesDeadline);
        self::assertSame($expectedRoute, $action->primary->route);
        self::assertNull($action->close, 'A row must never carry two close controls.');
    }

    /**
     * The decision not to challenge the order posts to its own route and carries its own
     * token id. Both matter: the audit trail has to tell a decision apart from the ten
     * days simply lapsing, and a token id left pointing at the generic close would make
     * every such post fail CSRF validation.
     */
    public function testTheAnnulmentWindowIsClosedByDecliningIt(): void
    {
        $deadline = $this->item(DeadlineType::CERERE_IN_ANULARE, daysRemaining: 5);
        $action = $this->resolver->resolve($deadline);

        self::assertSame('case_deadline_no_annulment_request', $action->primary->route);
        self::assertSame('POST', $action->primary->method);
        self::assertStringStartsWith('no_annulment_request_', (string) $action->primary->csrfTokenId);
        self::assertSame('deadlines.action.note.no_annulment_request', $action->primary->note);
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

    public function testExpiredSummonsAnswerOffersFilingThePaymentOrder(): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::RASPUNS_SOMATIE, daysRemaining: -2, certainty: DeadlineCertainty::CERT),
        );

        self::assertSame('deadlines.action.generate_payment_order', $action->primary->label);
        self::assertSame('case_payment_order_generate', $action->primary->route);
        self::assertNotNull($action->close);
    }

    /**
     * The agenda never posts the filing itself. The button carries no CSRF token id, so
     * {@see \App\Service\Deadline\DeadlineActionButton::needsCaseDialog()} is true and the
     * row renders a link into the case rather than a form of its own.
     *
     * That is what keeps the proof of communication (CPC art. 1015 para. 1) a single
     * gate: a form here would be a second entry point into
     * {@see \App\Controller\Case\CasePaymentOrderController::generate()}, offered from a
     * screen that shows neither the stamp duty nor the proof, and pressed from a row
     * whose case may be missing both.
     */
    #[DataProvider('filingFromTheAgendaProvider')]
    public function testFilingIsNeverPostedStraightFromTheAgenda(DeadlineType $type, int $daysRemaining): void
    {
        $action = $this->resolver->resolve(
            $this->item($type, daysRemaining: $daysRemaining, certainty: DeadlineCertainty::CERT, status: CaseStatus::SOMATIE_TRIMISA),
        );

        self::assertSame('case_payment_order_generate', $action->primary->route);
        self::assertNull($action->primary->csrfTokenId);
        self::assertTrue($action->primary->needsCaseDialog(), 'The row has to lead into the case, where every gate of the filing is shown.');
        self::assertNull($action->primary->agendaDialogId, 'The agenda carries no dialog for the filing.');
    }

    /** @return iterable<string, array{DeadlineType, int}> */
    public static function filingFromTheAgendaProvider(): iterable
    {
        yield 'expired summons answer' => [DeadlineType::RASPUNS_SOMATIE, -2];
        yield 'claim limitation' => [DeadlineType::PRESCRIPTIE, 12];
        yield 'filing the request' => [DeadlineType::DEPUNERE_CERERE, 6];
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
        // The package exists and only the filing is missing, which is the very act
        // that stops this term running.
        yield 'petition generated' => [CaseStatus::CERERE_GENERATA, 'deadlines.action.confirm_filing'];
        yield 'already filed' => [CaseStatus::DOSAR_INREGISTRAT, 'deadlines.action.open_case'];
    }

    #[DataProvider('limitationStageProvider')]
    public function testLimitationOffersTheActThatStopsItAtEachStage(CaseStatus $status, string $expectedLabel): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::PRESCRIPTIE, daysRemaining: 12, status: $status),
        );

        self::assertSame($expectedLabel, $action->primary->label);
        self::assertNull($action->close, 'A limitation term cannot be dismissed from the agenda.');
    }

    /**
     * Enforcement runs through a bailiff, outside the platform, and the term closes on
     * its own when enforcement starts. So the row states the term and leads into the
     * case, with nothing to press.
     */
    public function testEnforcementLimitationOnlyLeadsIntoTheCase(): void
    {
        $action = $this->resolver->resolve($this->item(DeadlineType::PRESCRIPTIE_EXECUTARE, daysRemaining: 20));

        self::assertSame('deadlines.action.open_case', $action->primary->label);
        self::assertNull($action->close);
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
        self::assertFalse($action->hasCloseButton(), 'A limitation term is never dismissible from the agenda.');
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
     * third column says where the close lives, which is the same question as "what is
     * this row for": PRIMARY when the act IS closing the term, SECONDARY when closing
     * is a side move next to a real act, NONE for the three limitation terms, which the
     * agenda deliberately gives no way to dismiss.
     *
     * @return iterable<string, array{DeadlineType, string, string}>
     */
    public static function primaryActionPerTypeProvider(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE, 'deadlines.action.mark_stamped', self::CLOSE_PRIMARY];
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE, 'deadlines.action.no_annulment_request', self::CLOSE_PRIMARY];
        yield 'free form reminder' => [DeadlineType::OTHER, 'deadlines.action.mark_done', self::CLOSE_PRIMARY];
        yield 'summons answer' => [DeadlineType::RASPUNS_SOMATIE, 'deadlines.action.mark_done', self::CLOSE_PRIMARY];
        yield 'hearing' => [DeadlineType::JUDECATA, 'deadlines.action.open_portal', self::CLOSE_SECONDARY];
        // The case is at SOMATIE_TRIMISA, so what stops the limitation period is the
        // request filed in court (CPC art. 1015 para. 2).
        yield 'limitation' => [DeadlineType::PRESCRIPTIE, 'deadlines.action.generate_payment_order', self::CLOSE_NONE];
        // Satisfied by the same act as the limitation term, the request reaching the
        // court within the six months of NCC art. 2540, so it offers the same button.
        yield 'filing the request' => [DeadlineType::DEPUNERE_CERERE, 'deadlines.action.generate_payment_order', self::CLOSE_NONE];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE, 'deadlines.action.open_case', self::CLOSE_NONE];
    }

    #[DataProvider('primaryActionPerTypeProvider')]
    public function testPrimaryActionPerType(DeadlineType $type, string $expectedLabel, string $expectedClosePosition): void
    {
        $action = $this->resolver->resolve($this->item($type, daysRemaining: 6));

        self::assertSame($expectedLabel, $action->primary->label);
        self::assertSame($expectedClosePosition === self::CLOSE_SECONDARY, $action->close !== null);
        self::assertSame($expectedClosePosition === self::CLOSE_PRIMARY, $action->primary->closesDeadline);
        self::assertSame($expectedClosePosition !== self::CLOSE_NONE, $action->hasCloseButton());

        if ($expectedClosePosition === self::CLOSE_NONE) {
            return;
        }

        // Whichever side the close lands on, it posts to an existing route carrying the
        // token that route validates. Nothing on this page closes a deadline any other
        // way, and the annulment window is closed through the named decision rather than
        // through the generic close.
        $close = $action->close ?? $action->primary;
        self::assertSame(
            $type === DeadlineType::CERERE_IN_ANULARE ? 'case_deadline_no_annulment_request' : 'case_deadline_complete',
            $close->route,
        );
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
            estimateNote: new DeadlineEstimateNote('mark', 'note'),
        );
    }
}
