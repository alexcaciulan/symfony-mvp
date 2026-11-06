<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\FilingChannel;
use App\Enum\StampDutyStatus;
use App\Service\Case\CaseNextStepFacts;
use App\Service\Case\CaseNextStepResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaseNextStepResolverTest extends TestCase
{
    private function caseIn(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setStatus($status);

        return $case;
    }

    private function resolve(LegalCase $case, ?CaseNextStepFacts $facts = null): \App\Service\Case\CaseNextStep
    {
        return (new CaseNextStepResolver())->resolve($case, $facts ?? new CaseNextStepFacts(hasCommunicationProof: true));
    }

    public function testASummonsWithoutACommunicationDateBlocksEvenWhenAProofIsAttached(): void
    {
        $step = $this->resolve($this->caseIn(CaseStatus::SOMATIE_TRIMISA));

        self::assertSame(CaseNextStepResolver::SUMMONS_BLOCKED, $step->blockedBy);
        self::assertSame('summons_blocked', $step->sentenceKey);
    }

    public function testACommunicatedSummonsWithoutProofBlocksOnTheProof(): void
    {
        $case = $this->caseIn(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-10-01'));

        self::assertSame(
            CaseNextStepResolver::PROOF_BLOCKED,
            $this->resolve($case, new CaseNextStepFacts(hasCommunicationProof: false))->blockedBy,
        );
    }

    public function testACommunicatedSummonsWithProofStatesTheStatusSentence(): void
    {
        $case = $this->caseIn(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-10-01'));

        $step = $this->resolve($case);

        self::assertNull($step->blockedBy);
        self::assertSame('SOMATIE_TRIMISA', $step->sentenceKey);
    }

    #[DataProvider('rulingStatuses')]
    public function testAnOrderWithoutACommunicationDateBlocks(CaseStatus $status): void
    {
        self::assertSame(CaseNextStepResolver::RULING_BLOCKED, $this->resolve($this->caseIn($status))->blockedBy);
    }

    /** @return iterable<string, array{CaseStatus}> */
    public static function rulingStatuses(): iterable
    {
        yield 'issued' => [CaseStatus::ORDONANTA_EMISA];
        yield 'under annulment' => [CaseStatus::IN_ANULARE];
    }

    public function testAPortalRulingProposalComesBeforeTheStatusSentence(): void
    {
        $step = $this->resolve($this->caseIn(CaseStatus::TERMEN_FIXAT), new CaseNextStepFacts(hasCommunicationProof: true, portalRulingProposal: true));

        self::assertSame(CaseNextStepResolver::PORTAL_RULING_BLOCKED, $step->blockedBy);
    }

    public function testAnUnpaidStampDutyBlocksOnceTheClaimIsAtTheCourt(): void
    {
        $case = $this->caseIn(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('123/212/2026');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);

        self::assertSame(CaseNextStepResolver::STAMP_DUTY_BLOCKED, $this->resolve($case)->blockedBy);
    }

    public function testACaseWithAnEmailFilingLeadsToTheFilingTab(): void
    {
        $step = $this->resolve($this->caseIn(CaseStatus::CERERE_GENERATA), new CaseNextStepFacts(
            hasCommunicationProof: true,
            filingPanelReady: true,
            hasEmailFiling: true,
        ));

        self::assertTrue($step->filingLink);
        self::assertSame('filing_ready', $step->sentenceKey);
    }

    public function testAGeneratedRequestWithoutAPackageKeepsTheManualConfirmation(): void
    {
        $step = $this->resolve($this->caseIn(CaseStatus::CERERE_GENERATA), new CaseNextStepFacts(
            hasCommunicationProof: true,
            filingPanelReady: true,
        ));

        self::assertFalse($step->filingLink);
        self::assertSame('CERERE_GENERATA', $step->sentenceKey);
    }

    public function testATransmittedFilingStaysTheNextStepUntilItsDateIsDeclared(): void
    {
        $case = $this->caseIn(CaseStatus::CERERE_DEPUSA);
        $case->setFilingChannel(FilingChannel::EMAIL);
        $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $facts = new CaseNextStepFacts(hasCommunicationProof: true, filingPanelReady: true);

        self::assertSame('filing_follow', $this->resolve($case, $facts)->sentenceKey);

        $case->setFiledAt(new \DateTimeImmutable('2026-10-01'));

        self::assertSame('CERERE_DEPUSA', $this->resolve($case, $facts)->sentenceKey);
    }

    public function testAPaidInFullClosureHasItsOwnSentence(): void
    {
        $case = $this->caseIn(CaseStatus::INCHIS_SUCCES);
        $case->setFullPaymentDate(new \DateTimeImmutable('2026-10-01'));

        self::assertSame('INCHIS_SUCCES_paid', $this->resolve($case)->sentenceKey);
    }

    public function testACaseWithoutAnythingPendingStatesItsStatus(): void
    {
        self::assertSame('AMIABIL', $this->resolve($this->caseIn(CaseStatus::AMIABIL))->sentenceKey);
    }
}
