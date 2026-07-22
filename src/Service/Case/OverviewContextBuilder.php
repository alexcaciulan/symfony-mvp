<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\Entity\ClaimItem;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DocumentType;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Repository\AuditLogRepository;
use App\Repository\CourtPortalEventRepository;
use App\Repository\LegalDeadlineRepository;
use App\Service\Calculation\ClaimInterestAggregator;
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
        private readonly ClaimInterestAggregator $accessoryAggregator,
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
            // The positions replace the per-period accordion on a multi-position
            // case: one aggregate breakdown from the earliest due date would show
            // the very figure the positions exist to stop claiming.
            'claim_items' => $countingItems,
            'claim_item_accessories' => $this->computeItemAccessories($case, $countingItems),
            'has_communication_proof' => $this->hasCommunicationProof($case),
            'payment_term_expired' => $this->deadlineService->isPaymentTermExpired($case, new \DateTimeImmutable('today')),
            'execution_recommended_date' => $this->deadlineService->recommendedExecutionDate($case),
            'document_upload_types' => DocumentType::uploadableTypes(),
            'stamp_duty_target' => $this->stampDutyUatResolver->resolve($case),
            'stamp_duty_proof' => $this->findStampDutyProof($case),
            // The duty is owed whether or not it was ever written onto the case (older
            // cases predate the field), so fall back to the statutory amount rather
            // than telling the lawyer the duty is 0 lei.
            'stamp_duty_amount' => (float) ($case->getStampDuty() ?? $this->stampDutyCalculator->calculate()->amount),
            'just_created' => false,
        ], $extra);
    }

    /**
     * Accessory per position, at the same reference date as the stored
     * `calculatedInterest` (the case creation date), so the per-position figures
     * add up to the total shown in the stats instead of drifting past it.
     *
     * @param list<ClaimItem> $items
     */
    private function computeItemAccessories(LegalCase $case, array $items): ?AggregatedAccessoryResult
    {
        if (count($items) < 2) {
            return null;
        }

        $rate = $case->getContractualPenaltyRate();

        try {
            return $this->accessoryAggregator->aggregate(
                items: $items,
                referenceDate: $case->getCreatedAt(),
                relationshipType: $case->getRelationshipType() ?? RelationshipType::COMERCIAL,
                penaltyType: $case->getPenaltyType() ?? PenaltyType::LEGAL_PENALIZATOARE,
                contractualDailyRate: $rate !== null ? (float) $rate : null,
            );
        } catch (\DomainException | \RuntimeException | \InvalidArgumentException) {
            return null;
        }
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
     * The reference date is the case creation date (not "now"): the stored
     * `calculatedInterest` was computed up to that date in the wizard, so using
     * it keeps the per-period rows, the table total and the stat in agreement
     * instead of drifting as interest accrues day by day.
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

        try {
            $result = $this->interestService->calculate(
                (float) $case->getAmount(),
                \DateTimeImmutable::createFromInterface($case->getDueDate()),
                $case->getCreatedAt(),
                $case->getRelationshipType(),
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
     * The most recent proof, not the first one found: the collection carries no
     * ordering guarantee, and showing a superseded proof under "view proof" would
     * misrepresent what was actually filed.
     */
    private function findStampDutyProof(LegalCase $case): ?Document
    {
        $proofs = [];
        foreach ($case->getDocuments() as $document) {
            if ($document->getDocumentType() === DocumentType::DOVADA_TAXA_TIMBRU) {
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
