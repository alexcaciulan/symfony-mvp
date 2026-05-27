<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DocumentType;
use App\Repository\AuditLogRepository;
use App\Repository\CourtPortalEventRepository;
use App\Repository\LegalDeadlineRepository;
use App\Service\Calculation\InterestCalculatorService;

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
        [$interestBreakdown, $breakdownError] = $this->computeBreakdown($case);

        return array_merge([
            'case' => $case,
            'deadlines' => $deadlines,
            'deadline_counters' => $this->countDeadlines($deadlines),
            'active_deadline' => $this->pickActiveDeadline($deadlines),
            'auditLogs' => $this->auditLogs->findByCase($case, 50),
            'portalEvents' => $this->portalEvents->findByLegalCase($case),
            'interest_breakdown' => $interestBreakdown,
            'breakdown_error' => $breakdownError,
            'has_communication_proof' => $this->hasCommunicationProof($case),
            'just_created' => false,
        ], $extra);
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
     * @return array{0: ?array, 1: bool}
     */
    private function computeBreakdown(LegalCase $case): array
    {
        if ($case->getAmount() === null || $case->getDueDate() === null || $case->getRelationshipType() === null) {
            return [null, false];
        }

        try {
            $result = $this->interestService->calculate(
                (float) $case->getAmount(),
                \DateTimeImmutable::createFromInterface($case->getDueDate()),
                new \DateTimeImmutable(),
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
