<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlineType;
use App\Enum\PortalCaseMatchSource;
use App\Service\Case\CaseNextStepFacts;
use App\Service\Case\CaseNextStepResolver;
use App\Service\Deadline\DeadlineAgendaItem;
use App\Service\Deadline\DeadlineConsequenceResolver;
use App\Service\Deadline\DeadlineEstimateNote;
use App\Service\Deadline\DeadlineNextStep;
use App\Service\Deadline\DeadlineNextStepResolver;
use App\Service\Deadline\DeadlineService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * What an agenda row suggests. A kernel test because the 15 days are the real arithmetic
 * of {@see DeadlineService}, prorogation included: a row suggesting the request one day
 * early would send the lawyer into an inadmissible filing (CPC art. 1015-1017).
 */
final class DeadlineNextStepResolverTest extends KernelTestCase
{
    private const COMMUNICATED_ON = '2026-03-02';

    private const SUGGESTING_TYPES = [DeadlineType::PRESCRIPTIE, DeadlineType::DEPUNERE_CERERE, DeadlineType::RASPUNS_SOMATIE];

    private DeadlineService $deadlineService;
    private DeadlineConsequenceResolver $consequenceResolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->deadlineService = static::getContainer()->get(DeadlineService::class);
        $this->consequenceResolver = new DeadlineConsequenceResolver();
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function suggestingTypeProvider(): iterable
    {
        foreach (self::SUGGESTING_TYPES as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('suggestingTypeProvider')]
    public function testBeforeTheSummonsTheRowSuggestsTheSummons(DeadlineType $type): void
    {
        $step = $this->resolve($this->item($type, $this->caseIn(CaseStatus::AMIABIL)));

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::SUMMONS, $step->code);
        self::assertSame('case_overview.hero.next_step.AMIABIL', $step->sentenceKey);
    }

    /** A band on the case comes first, and the row does not get ahead of it. */
    public function testBeforeTheSummonsABandOfTheCaseSilencesTheRow(): void
    {
        $case = $this->caseIn(CaseStatus::AMIABIL);
        $case->proposePortalMatch('123/4/2026', PortalCaseMatchSource::cases()[0]);

        self::assertNull($this->resolve($this->item(DeadlineType::PRESCRIPTIE, $case)));
    }

    #[DataProvider('suggestingTypeProvider')]
    public function testAnUnrecordedCommunicationAsksForTheDate(DeadlineType $type): void
    {
        $step = $this->resolve($this->item($type, $this->caseIn(CaseStatus::SOMATIE_TRIMISA)));

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::COMMUNICATION_DATE, $step->code);
        self::assertSame('deadlines.next_step.sentence.summons_blocked', $step->sentenceKey);
    }

    #[DataProvider('suggestingTypeProvider')]
    public function testARecordedCommunicationWithoutProofAsksForTheProof(DeadlineType $type): void
    {
        $step = $this->resolve(
            $this->item($type, $this->communicatedCase()),
            new CaseNextStepFacts(hasCommunicationProof: false),
        );

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::PROOF, $step->code);
        self::assertSame('deadlines.next_step.sentence.proof_blocked', $step->sentenceKey);
    }

    #[DataProvider('suggestingTypeProvider')]
    public function testWhileTheFifteenDaysRunTheRowStaysSilentAndThePanelHasTheSentence(DeadlineType $type): void
    {
        $step = $this->resolve($this->item($type, $this->communicatedCase()), today: self::COMMUNICATED_ON);

        self::assertNotNull($step);
        self::assertNull($step->code);
        self::assertSame('case_overview.hero.next_step.SOMATIE_TRIMISA', $step->sentenceKey);
    }

    /** The term runs through its maturity day: filing then would be premature. */
    public function testTheMaturityDayItselfStillGivesNoCode(): void
    {
        $termEnd = $this->deadlineService->paymentTermEnd($this->communicatedCase());
        self::assertNotNull($termEnd);

        $step = $this->resolve(
            $this->item(DeadlineType::RASPUNS_SOMATIE, $this->communicatedCase()),
            today: $termEnd->format('Y-m-d'),
        );

        self::assertNotNull($step);
        self::assertNull($step->code);
    }

    public function testTheDayAfterTheMaturitySuggestsTheRequest(): void
    {
        $termEnd = $this->deadlineService->paymentTermEnd($this->communicatedCase());
        self::assertNotNull($termEnd);

        $step = $this->resolve(
            $this->item(DeadlineType::RASPUNS_SOMATIE, $this->communicatedCase(), daysRemaining: -1),
            today: $termEnd->modify('+1 day')->format('Y-m-d'),
        );

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::PAYMENT_ORDER, $step->code);
        self::assertSame('case_overview.hero.next_step.SOMATIE_TRIMISA', $step->sentenceKey);
    }

    /** A band that is not one of the two the row translates leaves the row silent. */
    public function testAnotherBandOnASummonedCaseSilencesTheRow(): void
    {
        $case = $this->communicatedCase();
        $case->proposePortalMatch('123/4/2026', PortalCaseMatchSource::cases()[0]);

        self::assertNull($this->resolve($this->item(DeadlineType::PRESCRIPTIE, $case)));
    }

    public function testAGeneratedRequestWithoutAnEmailFilingSuggestsTheFilingItself(): void
    {
        $step = $this->resolve(
            $this->item(DeadlineType::PRESCRIPTIE, $this->caseIn(CaseStatus::CERERE_GENERATA)),
            new CaseNextStepFacts(hasCommunicationProof: false, filingPanelReady: true, hasEmailFiling: false),
        );

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::FILING, $step->code);
        self::assertSame('case_overview.hero.next_step.CERERE_GENERATA', $step->sentenceKey);
    }

    public function testAGeneratedRequestWithAnEmailFilingSuggestsThePackage(): void
    {
        $step = $this->resolve(
            $this->item(DeadlineType::DEPUNERE_CERERE, $this->caseIn(CaseStatus::CERERE_GENERATA)),
            new CaseNextStepFacts(hasCommunicationProof: false, filingPanelReady: true, hasEmailFiling: true),
        );

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::FILING_PACKAGE, $step->code);
        self::assertSame('case_overview.hero.next_step.filing_ready', $step->sentenceKey);
    }

    /** An email filing with the panel gone (a paid case) is not a package to send. */
    public function testAnEmailFilingOnACaseWithoutThePanelFallsBackToTheFiling(): void
    {
        $step = $this->resolve(
            $this->item(DeadlineType::DEPUNERE_CERERE, $this->caseIn(CaseStatus::CERERE_GENERATA)),
            new CaseNextStepFacts(hasCommunicationProof: false, filingPanelReady: false, hasEmailFiling: true),
        );

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::FILING, $step->code);
    }

    public function testAGeneratedRequestUnderABandSilencesTheRow(): void
    {
        $case = $this->caseIn(CaseStatus::CERERE_GENERATA);
        $case->proposePortalMatch('123/4/2026', PortalCaseMatchSource::cases()[0]);

        self::assertNull($this->resolve($this->item(DeadlineType::PRESCRIPTIE, $case)));
    }

    /** @return iterable<string, array{CaseStatus}> */
    public static function silentStatusProvider(): iterable
    {
        foreach (CaseStatus::cases() as $status) {
            if (!\in_array($status, [CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA, CaseStatus::CERERE_GENERATA], true)) {
                yield $status->value => [$status];
            }
        }
    }

    /** Past the filing a row has nothing to suggest: the case page leads from there. */
    #[DataProvider('silentStatusProvider')]
    public function testEveryOtherStatusSuggestsNothing(CaseStatus $status): void
    {
        self::assertNull($this->resolve($this->item(DeadlineType::PRESCRIPTIE, $this->caseIn($status))));
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function silentTypeProvider(): iterable
    {
        foreach (DeadlineType::cases() as $type) {
            if (!\in_array($type, self::SUGGESTING_TYPES, true)) {
                yield $type->value => [$type];
            }
        }
    }

    #[DataProvider('silentTypeProvider')]
    public function testEveryOtherTypeSuggestsNothing(DeadlineType $type): void
    {
        self::assertNull($this->resolve($this->item($type, $this->caseIn(CaseStatus::AMIABIL))));
        self::assertNull($this->resolve($this->item($type, $this->caseIn(CaseStatus::SOMATIE_TRIMISA))));
        self::assertNull($this->resolve($this->item($type, $this->caseIn(CaseStatus::CERERE_GENERATA))));
    }

    #[DataProvider('suggestingTypeProvider')]
    public function testAClosedTermSuggestsNothing(DeadlineType $type): void
    {
        $item = $this->item($type, $this->caseIn(CaseStatus::AMIABIL));
        $item->deadline->setCompleted(true);

        self::assertNull($this->resolve($item));
    }

    /** A limitation term that has run out cannot be interrupted by a summons any more. */
    public function testALimitationTermThatRanOutSuggestsNothing(): void
    {
        $item = $this->item(DeadlineType::PRESCRIPTIE, $this->caseIn(CaseStatus::AMIABIL), daysRemaining: -1);

        self::assertNull($this->resolve($item));
    }

    public function testALimitationTermOnItsLastDayStillSuggests(): void
    {
        $step = $this->resolve($this->item(DeadlineType::PRESCRIPTIE, $this->caseIn(CaseStatus::AMIABIL), daysRemaining: 0));

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::SUMMONS, $step->code);
    }

    public function testALimitationRowThatRanOutSuggestsNothing(): void
    {
        $item = $this->item(DeadlineType::PRESCRIPTIE, $this->caseIn(CaseStatus::AMIABIL), daysRemaining: -3);

        self::assertNull($this->resolve($item));
    }

    /** Only the limitation term goes silent: the others follow the case, not their date. */
    public function testAnExpiredFilingTermStillSuggests(): void
    {
        $step = $this->resolve($this->item(DeadlineType::DEPUNERE_CERERE, $this->caseIn(CaseStatus::AMIABIL), daysRemaining: -3));

        self::assertNotNull($step);
        self::assertSame(DeadlineNextStep::SUMMONS, $step->code);
    }

    /**
     * The row and the header read one rule: wherever the header reports a band, the row
     * either names that same act or says nothing.
     */
    public function testTheRowNeverContradictsTheCaseHeader(): void
    {
        $caseResolver = new CaseNextStepResolver();

        foreach ([CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA, CaseStatus::CERERE_GENERATA] as $status) {
            foreach ([true, false] as $proof) {
                $facts = new CaseNextStepFacts(hasCommunicationProof: $proof);
                $case = $this->caseIn($status);
                $step = $this->resolve($this->item(DeadlineType::PRESCRIPTIE, $case), $facts);
                $blockedBy = $caseResolver->resolve($case, $facts)->blockedBy;

                if ($blockedBy === null) {
                    self::assertNotNull($step);
                    continue;
                }

                self::assertSame(DeadlineNextStep::COMMUNICATION_DATE, $step?->code);
                self::assertSame(CaseNextStepResolver::SUMMONS_BLOCKED, $blockedBy);
            }
        }
    }

    private function resolve(DeadlineAgendaItem $item, ?CaseNextStepFacts $facts = null, string $today = '2026-06-01'): ?DeadlineNextStep
    {
        $resolver = new DeadlineNextStepResolver(new CaseNextStepResolver(), $this->deadlineService, new MockClock($today));

        return $resolver->resolve($item, $facts ?? new CaseNextStepFacts(hasCommunicationProof: true));
    }

    private function caseIn(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setStatus($status);

        return $case;
    }

    private function communicatedCase(): LegalCase
    {
        $case = $this->caseIn(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable(self::COMMUNICATED_ON));

        return $case;
    }

    private function item(DeadlineType $type, LegalCase $case, int $daysRemaining = 6): DeadlineAgendaItem
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable('2026-06-07'));

        return new DeadlineAgendaItem(
            deadline: $deadline,
            consequence: $this->consequenceResolver->resolve($type),
            certainty: DeadlineCertainty::CERT,
            daysRemaining: $daysRemaining,
            estimateNote: new DeadlineEstimateNote('mark', 'note'),
        );
    }
}
