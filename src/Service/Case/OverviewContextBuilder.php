<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\PenaltyBreakdownRow;
use App\Entity\ClaimItem;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\FilingChannel;
use App\Enum\PenaltyType;
use App\Repository\AuditLogRepository;
use App\Repository\CourtPortalEventRepository;
use App\Repository\LegalDeadlineRepository;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Calculation\StampDutyCalculator;
use App\Service\Deadline\DeadlineService;
use App\Service\Portal\RulingProposalResolver;
use App\Service\StampDuty\StampDutyUatResolver;

/**
 * Builds the Twig context for the case overview page in a single place
 * so the initial GET render (CaseOverviewController) and the Turbo
 * Stream multi-fragment response after a workflow transition
 * (CaseTransitionController) produce a consistent view of the case.
 */
final class OverviewContextBuilder
{
    public function __construct(
        private readonly LegalDeadlineRepository $deadlines,
        private readonly AuditLogRepository $auditLogs,
        private readonly CourtPortalEventRepository $portalEvents,
        private readonly InterestCalculatorService $interestService,
        private readonly DeadlineService $deadlineService,
        private readonly RulingProposalResolver $rulingProposalResolver,
        private readonly StampDutyUatResolver $stampDutyUatResolver,
        private readonly StampDutyCalculator $stampDutyCalculator,
        private readonly CaseAccessoryService $caseAccessories,
        private readonly CaseFullPaymentClosureService $fullPaymentClosure,
    ) {}

    /**
     * @param array<string, mixed> $extra Overrides or additions on top of
     *                                    the base context (e.g. `just_created`
     *                                    flag from the wizard redirect badge).
     *
     * @return array<string, mixed>
     */
    public function build(LegalCase $case, array $extra = []): array
    {
        $deadlines = $this->deadlines->findByCase($case);
        $portalEvents = $this->portalEvents->findByLegalCase($case);
        [$interestBreakdown, $breakdownError] = $this->computeBreakdown($case);
        $countingItems = $case->getCountingClaimItems();
        $accessories = $this->caseAccessories->aggregate($case);

        return array_merge([
            'case' => $case,
            'deadlines' => $deadlines,
            'deadline_counters' => $this->countDeadlines($deadlines),
            'active_deadline' => $this->pickActiveDeadline($deadlines),
            'auditLogs' => $this->auditLogs->findByCase($case, 50),
            'portalEvents' => $portalEvents,
            'portal_ruling_proposal' => $this->rulingProposalResolver->actionableProposal($case, $portalEvents),
            'interest_breakdown' => $interestBreakdown,
            'breakdown_error' => $breakdownError,
            // Free-text description of what the claim is about, entered in the wizard.
            // Shown on the claim card when present; empty on older cases predating it.
            'claim_description' => $case->getClaimDescription(),
            // The positions replace the per-period accordion on a multi-position
            // case: one aggregate breakdown from the earliest due date would show
            // the very figure the positions exist to stop claiming.
            'claim_items' => $countingItems,
            'claim_item_accessories' => count($countingItems) > 1 ? $accessories : null,
            'penalty_breakdown' => $this->penaltyBreakdown($case, $countingItems, $accessories),
            'accessory_reference_date' => $this->caseAccessories->referenceDate($case),
            'has_communication_proof' => $this->hasCommunicationProof($case),
            'communication_proof' => $this->findLatestDocument($case, DocumentType::DOVADA_COMUNICARE),
            'payment_term_end' => $this->deadlineService->paymentTermEnd($case),
            'payment_term_expired' => $this->deadlineService->isPaymentTermExpired($case, new \DateTimeImmutable('today')),
            'execution_recommended_date' => $this->deadlineService->recommendedExecutionDate($case),
            'document_upload_types' => DocumentType::uploadableTypes(),
            'filing_channels' => FilingChannel::cases(),
            'stamp_duty_target' => $this->stampDutyUatResolver->resolve($case),
            'stamp_duty_proof' => $this->findLatestDocument($case, DocumentType::DOVADA_TAXA_TIMBRU),
            // The duty is owed whether or not it was ever written onto the case (older
            // cases predate the field), so fall back to the statutory amount rather
            // than telling the lawyer the duty is 0 lei.
            'stamp_duty_amount' => (float) ($case->getStampDuty() ?? $this->stampDutyCalculator->calculate()->amount),
            'can_close_full_payment' => $this->fullPaymentClosure->canClose($case),
            'closed_from_status' => $this->closedFromStatus($case),
            'debtor_count' => $case->getDebtors()->count(),
            'just_created' => false,
        ], $extra);
    }

    /**
     * The status a terminal case was closed from, so the pipeline can stop at the
     * stage the case actually reached. Terminal statuses have no way out, so there is
     * a single history entry into one. Null when the case is not terminal or predates
     * the history.
     */
    private function closedFromStatus(LegalCase $case): ?CaseStatus
    {
        if (!$case->getStatus()->isTerminal()) {
            return null;
        }

        foreach ($case->getStatusHistory() as $entry) {
            if ($entry->getNewStatus() === $case->getStatus()->value) {
                return CaseStatus::tryFrom($entry->getOldStatus());
            }
        }

        // The history entry is written while the closing flushes, so a response built
        // in the same request may not see it yet. A full-payment closing can only come
        // from two places, and the summons date tells them apart.
        if ($case->getFullPaymentDate() !== null) {
            return $case->getPaymentNoticeDate() !== null ? CaseStatus::SOMATIE_TRIMISA : CaseStatus::AMIABIL;
        }

        return null;
    }

    /**
     * Contractual penalty per position, at the date the case states it.
     *
     * @param list<ClaimItem> $items
     * @return list<PenaltyBreakdownRow>|null
     */
    private function penaltyBreakdown(LegalCase $case, array $items, ?AggregatedAccessoryResult $accessories): ?array
    {
        if ($accessories === null || $case->getPenaltyType() !== PenaltyType::CONTRACTUAL) {
            return null;
        }

        $rows = [];
        foreach ($items as $item) {
            $penalty = $accessories->penaltyByItemId[$item->getId()] ?? null;
            $row = $penalty !== null
                ? PenaltyBreakdownRow::fromResult($penalty, $item->signedAmountRon() ?? 0.0, $case->contractualPenaltyCap(), $item->getDocumentNumber())
                : null;
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param LegalDeadline[] $deadlines ordered ASC by deadlineDate per findByCase()
     */
    private function pickActiveDeadline(array $deadlines): ?LegalDeadline
    {
        $now = new \DateTimeImmutable();
        foreach ($deadlines as $deadline) {
            if ($deadline->isCompleted()) {
                continue;
            }
            if ($deadline->getDeadlineDate() > $now) {
                return $deadline;
            }
        }

        return null;
    }

    /**
     * Recomputes the interest breakdown server-side for the BNR accordion in Tab Detalii.
     * Returns `[breakdown[], errorFlag]`. The breakdown is not persisted — only used
     * to drive the per-period table render.
     *
     * The reference date is the one the stored `calculatedInterest` holds (see
     * CaseAccessoryService::referenceDate()), so rows, total and stat agree.
     *
     * @return array{0: ?array, 1: bool}
     */
    private function computeBreakdown(LegalCase $case): array
    {
        // With several positions, one aggregate breakdown from the earliest due
        // date would claim interest nobody owes, so the accordion shows periods
        // only for a single-position case and the positions themselves otherwise.
        if (count($case->getCountingClaimItems()) > 1) {
            return [null, false];
        }

        if ($case->getAmount() === null || $case->getDueDate() === null || $case->getRelationshipType() === null) {
            return [null, false];
        }

        // A contractual penalty runs on the contract's daily rate, not on BNR
        // periods: a period table here would explain a figure the case does not hold.
        if ($case->getPenaltyType() === PenaltyType::CONTRACTUAL && $case->getContractualPenaltyRate() !== null) {
            return [null, false];
        }

        try {
            $result = $this->interestService->calculate(
                (float) $case->getAmount(),
                \DateTimeImmutable::createFromInterface($case->getDueDate()),
                $this->caseAccessories->referenceDate($case),
                $case->getRelationshipType(),
                contractDate: $case->contractDateImmutable(),
            );

            return [$result->breakdown, false];
        } catch (\InvalidArgumentException | \RuntimeException | \DomainException) {
            return [null, true];
        }
    }

    /**
     * Whether the case already has a DOVADA_COMUNICARE document attached.
     * Uses `Collection::exists()` so the EXTRA_LAZY collection is hydrated
     * once and reused by every partial on the page.
     */
    private function hasCommunicationProof(LegalCase $case): bool
    {
        return $case->getDocuments()->exists(
            static fn (int $_key, Document $doc): bool => $doc->getDocumentType() === DocumentType::DOVADA_COMUNICARE,
        );
    }

    /**
     * The most recent document of the given type, not the first one found: the
     * collection carries no ordering guarantee, and showing a superseded proof under
     * "view proof" would misrepresent what was actually filed.
     */
    private function findLatestDocument(LegalCase $case, DocumentType $type): ?Document
    {
        $proofs = [];
        foreach ($case->getDocuments() as $document) {
            if ($document->getDocumentType() === $type) {
                $proofs[] = $document;
            }
        }

        if ($proofs === []) {
            return null;
        }

        usort($proofs, static fn (Document $a, Document $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $proofs[0];
    }

    /**
     * @param LegalDeadline[] $deadlines
     * @return array{active: int, expired: int, completed: int}
     */
    private function countDeadlines(array $deadlines): array
    {
        $now = new \DateTimeImmutable();
        $counts = ['active' => 0, 'expired' => 0, 'completed' => 0];
        foreach ($deadlines as $deadline) {
            if ($deadline->isCompleted()) {
                $counts['completed']++;
            } elseif ($deadline->getDeadlineDate() <= $now) {
                $counts['expired']++;
            } else {
                $counts['active']++;
            }
        }

        return $counts;
    }
}
