<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\FilingChannel;
use App\Enum\StampDutyStatus;

/**
 * The single rule for what comes next on a case, shared by the case header and the
 * deadlines agenda so the two can never give the lawyer different advice.
 *
 * Each blocking test mirrors the visibility test of a band on the case page: when a band
 * with a filled button is on screen, that act comes first.
 */
final class CaseNextStepResolver
{
    public const SUMMONS_BLOCKED = 'summons_blocked';
    public const PROOF_BLOCKED = 'proof_blocked';
    public const RULING_BLOCKED = 'ruling_blocked';
    public const PORTAL_RULING_BLOCKED = 'portal_ruling_blocked';
    public const PORTAL_NUMBER_BLOCKED = 'portal_number_blocked';
    public const STAMP_DUTY_BLOCKED = 'stamp_duty_blocked';
    public const ANNULMENT_DATE_PENDING = 'annulment_date_pending';

    public function resolve(LegalCase $case, CaseNextStepFacts $facts): CaseNextStep
    {
        $blockedBy = $this->blockedBy($case, $facts);
        $filingLink = $this->leadsToFilingTab($case, $facts);

        return new CaseNextStep($blockedBy, $filingLink, $this->sentenceKey($case, $blockedBy, $filingLink));
    }

    private function blockedBy(LegalCase $case, CaseNextStepFacts $facts): ?string
    {
        $status = $case->getStatus();
        $hasCourtNumber = (string) $case->getCourtCaseNumber() !== '';

        return match (true) {
            $status === CaseStatus::SOMATIE_TRIMISA && $case->getPaymentNoticeCommunicationDate() === null => self::SUMMONS_BLOCKED,
            $status === CaseStatus::SOMATIE_TRIMISA && !$facts->hasCommunicationProof => self::PROOF_BLOCKED,
            \in_array($status, [CaseStatus::ORDONANTA_EMISA, CaseStatus::IN_ANULARE], true) && $case->getRulingCommunicationDate() === null => self::RULING_BLOCKED,
            $facts->portalRulingProposal => self::PORTAL_RULING_BLOCKED,
            !$status->isTerminal() && !$hasCourtNumber && (string) $case->getPortalProposedNumber() !== '' => self::PORTAL_NUMBER_BLOCKED,
            !$status->isTerminal() && $hasCourtNumber && $case->hasReachedCourt()
                && \in_array($case->getStampDutyStatus(), [StampDutyStatus::NEACHITATA, StampDutyStatus::AMANATA_REGULARIZARE], true) => self::STAMP_DUTY_BLOCKED,
            // Not a precondition of enforcement, so the sentence names it without "first".
            $status === CaseStatus::DEFINITIVA && $case->getAnnulmentRulingCommunicationDate() === null && $case->hasPassedThroughAnnulment() => self::ANNULMENT_DATE_PENDING,
            default => null,
        };
    }

    /**
     * Once the case has an email filing, the header only leads to the filing tab: a manual
     * filing there would file the petition twice. After the transmission, the tab stays
     * the next step until the filing date is declared or while a late bounce waits.
     */
    private function leadsToFilingTab(LegalCase $case, CaseNextStepFacts $facts): bool
    {
        if (!$facts->filingPanelReady) {
            return false;
        }

        return match ($case->getStatus()) {
            CaseStatus::CERERE_GENERATA => $facts->hasEmailFiling,
            CaseStatus::CERERE_DEPUSA => $case->getFilingChannel() === FilingChannel::EMAIL
                && ($case->getFiledAt() === null || $facts->lateBounceReview),
            default => false,
        };
    }

    private function sentenceKey(LegalCase $case, ?string $blockedBy, bool $filingLink): string
    {
        $status = $case->getStatus();

        return match (true) {
            $blockedBy !== null => $blockedBy,
            $filingLink => $status === CaseStatus::CERERE_GENERATA ? 'filing_ready' : 'filing_follow',
            $status === CaseStatus::INCHIS_SUCCES && $case->getFullPaymentDate() !== null => 'INCHIS_SUCCES_paid',
            default => $status->value,
        };
    }
}
