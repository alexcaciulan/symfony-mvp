<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlineType;
use App\Service\Deadline\DeadlineActionButton;
use App\Service\Deadline\DeadlineAgendaItem;
use App\Service\Deadline\DeadlineConsequenceResolver;
use App\Service\Deadline\DeadlineEstimateNote;
use App\Service\Deadline\DeadlineNextStep;
use App\Service\Deadline\DeadlineRowActionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two controls of an agenda row. The rules under test are the ones a template
 * must never be allowed to reinvent: the first control always leads into the case, the
 * second closes the term and exists only where closing is honest, and nothing on a row
 * generates a document or moves the case to another stage.
 */
class DeadlineRowActionResolverTest extends TestCase
{
    /** Routes that spend a subscription slot or change the stage of the case. */
    private const GENERATING_ROUTES = ['case_summons_generate', 'case_payment_order_generate', 'case_transition_file'];

    private DeadlineRowActionResolver $resolver;
    private DeadlineConsequenceResolver $consequenceResolver;

    protected function setUp(): void
    {
        $this->resolver = new DeadlineRowActionResolver();
        $this->consequenceResolver = new DeadlineConsequenceResolver();
    }

    /**
     * The whole mapping in one place, one line per deadline type, read at a neutral
     * position: the date is certain and still ahead, so nothing is consumed yet.
     *
     * @return iterable<string, array{DeadlineType, string, ?string, ?string}>
     */
    public static function controlsPerTypeProvider(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE, 'deadlines.action.open_case', 'deadlines.action.mark_stamped', 'case_deadline_complete'];
        yield 'free form reminder' => [DeadlineType::OTHER, 'deadlines.action.open_case', 'deadlines.action.mark_done', 'case_deadline_complete'];
        yield 'summons answer' => [DeadlineType::RASPUNS_SOMATIE, 'deadlines.action.open_case', 'deadlines.action.mark_done', 'case_deadline_complete'];
        // The platform cannot draft the annulment request, so the only act it offers on
        // this window is the decision not to use it, on a route of its own.
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE, 'deadlines.action.open_case', 'deadlines.action.no_annulment_request', 'case_deadline_no_annulment_request'];
        yield 'hearing' => [DeadlineType::JUDECATA, 'deadlines.action.open_portal', 'deadlines.action.mark_done', 'case_deadline_complete'];
        yield 'limitation' => [DeadlineType::PRESCRIPTIE, 'deadlines.action.open_case', null, null];
        yield 'filing the request' => [DeadlineType::DEPUNERE_CERERE, 'deadlines.action.open_case', null, null];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE, 'deadlines.action.open_case', null, null];
    }

    #[DataProvider('controlsPerTypeProvider')]
    public function testTheTwoControlsOfEachType(DeadlineType $type, string $openLabel, ?string $closeLabel, ?string $closeRoute): void
    {
        $action = $this->resolver->resolve($this->item($type, daysRemaining: 6));

        self::assertSame($openLabel, $action->open->label);
        self::assertSame('case_overview', $action->open->route);
        self::assertSame('GET', $action->open->method);
        self::assertFalse($action->open->closesDeadline, 'The way into the case closes nothing.');

        if ($closeLabel === null) {
            self::assertNull($action->close);

            return;
        }

        self::assertNotNull($action->close);
        self::assertSame($closeLabel, $action->close->label);
        self::assertSame($closeRoute, $action->close->route);
        self::assertSame('POST', $action->close->method);
        self::assertTrue($action->close->closesDeadline);
        self::assertNotNull($action->close->csrfTokenId);
        self::assertArrayHasKey('deadlineId', $action->close->routeParameters);
    }

    /** A type added to the enum without a line above would silently go untested. */
    public function testEveryTypeIsPinnedByTheMappingAbove(): void
    {
        $covered = array_map(
            static fn (array $row): DeadlineType => $row[0],
            iterator_to_array(self::controlsPerTypeProvider()),
        );

        self::assertEqualsCanonicalizing(DeadlineType::cases(), array_values($covered));
    }

    /**
     * The decision not to challenge the order posts to its own route and carries its own
     * token id. Both matter: the audit trail has to tell a decision apart from the ten
     * days simply lapsing, and a token id left pointing at the generic close would make
     * every such post fail CSRF validation.
     */
    public function testTheAnnulmentWindowIsClosedByDecliningIt(): void
    {
        $close = $this->resolver->resolve($this->item(DeadlineType::CERERE_IN_ANULARE, daysRemaining: 5))->close;

        self::assertNotNull($close);
        self::assertSame('case_deadline_no_annulment_request', $close->route);
        self::assertSame('POST', $close->method);
        self::assertStringStartsWith('no_annulment_request_', (string) $close->csrfTokenId);
        self::assertSame('deadlines.action.note.no_annulment_request', $close->note);
    }

    public function testTheGenericCloseCarriesTheTokenOfItsRoute(): void
    {
        $close = $this->resolver->resolve($this->item(DeadlineType::TIMBRARE, daysRemaining: 5))->close;

        self::assertNotNull($close);
        self::assertStringStartsWith('complete_deadline_', (string) $close->csrfTokenId);
    }

    public function testHearingLeadsToThePortalTab(): void
    {
        $action = $this->resolver->resolve($this->item(DeadlineType::JUDECATA, daysRemaining: 1));

        self::assertSame('portal', $action->open->routeParameters['tab']);
        self::assertNotNull($action->close);
    }

    /** An expired term of the debtor stays closable: the act behind it is not on the row. */
    public function testAnExpiredSummonsAnswerLeadsIntoTheCaseAndCanBeClosed(): void
    {
        $action = $this->resolver->resolve($this->item(DeadlineType::RASPUNS_SOMATIE, daysRemaining: -2));

        self::assertSame('deadlines.action.open_case', $action->open->label);
        self::assertNotNull($action->close);
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function fatalTypeProvider(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE];
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE];
        yield 'limitation' => [DeadlineType::PRESCRIPTIE];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE];
        yield 'filing the request' => [DeadlineType::DEPUNERE_CERERE];
    }

    #[DataProvider('fatalTypeProvider')]
    public function testMissedFatalDeadlineOnACertainDateLosesItsClose(DeadlineType $type): void
    {
        $action = $this->resolver->resolve($this->item($type, daysRemaining: -4));

        self::assertSame('deadlines.action.open_case', $action->open->label);
        self::assertSame('case_overview', $action->open->route);
        self::assertNull($action->close, 'A consumed fatal term must not be silenceable.');
    }

    /** The term still runs on its last day (CPC art. 182 para. 1), so it stays closable. */
    public function testAFatalTermDueTodayKeepsItsClose(): void
    {
        self::assertNotNull($this->resolver->resolve($this->item(DeadlineType::TIMBRARE, daysRemaining: 0))->close);
    }

    /**
     * An estimated date says nothing about the consequence, so the row stays workable
     * even past its date. A term that is closable when certain keeps its close.
     */
    public function testMissedFatalDeadlineOnAnEstimatedDateKeepsItsClose(): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::TIMBRARE, daysRemaining: -9, certainty: DeadlineCertainty::ESTIMAT),
        );

        self::assertNotNull($action->close);
    }

    /** Estimated or not, a limitation term has no way off the agenda. */
    public function testAnEstimatedLimitationPastItsDateStillOffersNoClose(): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::PRESCRIPTIE, daysRemaining: -9, certainty: DeadlineCertainty::ESTIMAT),
        );

        self::assertSame('deadlines.action.open_case', $action->open->label);
        self::assertNull($action->close);
    }

    #[DataProvider('everyTypeProvider')]
    public function testAClosedTermKeepsOnlyTheWayIntoTheCase(DeadlineType $type): void
    {
        $item = $this->item($type, daysRemaining: 6);
        $item->deadline->setCompleted(true);

        $action = $this->resolver->resolve($item);

        self::assertSame('case_overview', $action->open->route);
        self::assertNull($action->close);
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function everyTypeProvider(): iterable
    {
        foreach (DeadlineType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    /** @return iterable<string, array{string, string, ?string, ?string}> */
    public static function suggestionProvider(): iterable
    {
        yield 'summons' => [DeadlineNextStep::SUMMONS, 'deadlines.action.open_case', 'deadlines.action.note.summons', null];
        yield 'payment order' => [DeadlineNextStep::PAYMENT_ORDER, 'deadlines.action.open_case', 'deadlines.action.note.payment_order', null];
        yield 'filing package' => [DeadlineNextStep::FILING_PACKAGE, 'deadlines.action.open_filing', 'deadlines.action.note.filing_package', 'depunere'];
        yield 'filing by hand' => [DeadlineNextStep::FILING, 'deadlines.action.open_case', null, null];
        yield 'communication date' => [DeadlineNextStep::COMMUNICATION_DATE, 'deadlines.action.open_case', null, null];
        yield 'proof' => [DeadlineNextStep::PROOF, 'deadlines.action.open_case', null, null];
    }

    /** The suggestion only words the link: pressing it still lands on the case. */
    #[DataProvider('suggestionProvider')]
    public function testTheSuggestionWordsTheWayIntoTheCase(string $code, string $label, ?string $note, ?string $tab): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::PRESCRIPTIE, daysRemaining: 12),
            new DeadlineNextStep($code, 'sentence'),
        );

        self::assertSame($label, $action->open->label);
        self::assertSame($note, $action->open->note);
        self::assertSame($tab, $action->open->routeParameters['tab'] ?? null);
        self::assertSame('GET', $action->open->method);
        self::assertNull($action->close);
    }

    public function testASilentSuggestionLeavesThePlainLink(): void
    {
        $action = $this->resolver->resolve(
            $this->item(DeadlineType::RASPUNS_SOMATIE, daysRemaining: 4),
            new DeadlineNextStep(null, 'sentence'),
        );

        self::assertSame('deadlines.action.open_case', $action->open->label);
        self::assertNull($action->open->note);
    }

    /**
     * Generating a summons costs a subscription slot and generating or filing a request
     * moves the case. None of them can be one click away on a triage screen, whatever the
     * row is. Every type crossed with every status, position and suggestion.
     */
    public function testNoButtonOfAnyRowGeneratesADocumentOrMovesTheCase(): void
    {
        $checked = 0;
        $suggestions = [null, ...array_map(
            static fn (string $code): DeadlineNextStep => new DeadlineNextStep($code, 'sentence'),
            [DeadlineNextStep::SUMMONS, DeadlineNextStep::COMMUNICATION_DATE, DeadlineNextStep::PROOF, DeadlineNextStep::PAYMENT_ORDER, DeadlineNextStep::FILING_PACKAGE, DeadlineNextStep::FILING],
        )];

        foreach (DeadlineType::cases() as $type) {
            foreach (CaseStatus::cases() as $status) {
                foreach ([-4, 0, 6] as $days) {
                    foreach ([DeadlineCertainty::CERT, DeadlineCertainty::ESTIMAT] as $certainty) {
                        foreach ($suggestions as $suggestion) {
                            $action = $this->resolver->resolve(
                                $this->item($type, $days, $certainty, $status),
                                $suggestion,
                            );
                            $this->assertGeneratesNothing($action->open);
                            if ($action->close !== null) {
                                $this->assertGeneratesNothing($action->close);
                            }
                            ++$checked;
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(1000, $checked);
    }

    private function assertGeneratesNothing(DeadlineActionButton $button): void
    {
        self::assertNotContains($button->route, self::GENERATING_ROUTES);
        self::assertContains($button->route, ['case_overview', 'case_deadline_complete', 'case_deadline_no_annulment_request']);
    }

    private function item(
        DeadlineType $type,
        int $daysRemaining,
        DeadlineCertainty $certainty = DeadlineCertainty::CERT,
        CaseStatus $status = CaseStatus::SOMATIE_TRIMISA,
        int $coveredTerms = 1,
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
            coveredTerms: $coveredTerms,
        );
    }
}
