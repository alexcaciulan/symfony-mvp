<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DocumentType;
use App\Repository\AuditLogRepository;
use App\Repository\CourtPortalEventRepository;
use App\Repository\DocumentRepository;
use App\Repository\LegalCaseRepository;
use App\Repository\LegalDeadlineRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Calculation\InterestCalculatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pas 4.0 — Pagina overview dosar.
 *
 * Foundation pentru cele 5 tab-uri (Detalii / Documente / Termene / Activitate Portal / Audit)
 * livrate progresiv în sub-pașii 4.0.2 → 4.0.7. La 4.0.3 livrăm Tab Detalii (parties + claim
 * composition + BNR breakdown + court summary + sidebar deadline ring + recommended actions).
 *
 * Detalii plan: docs/LexRecovery/PLAN-PAS-4-0-OVERVIEW-DOSAR.md
 */
#[IsGranted('ROLE_USER')]
final class CaseOverviewController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly LegalDeadlineRepository $deadlines,
        private readonly AuditLogRepository $auditLogs,
        private readonly CourtPortalEventRepository $portalEvents,
        private readonly DocumentRepository $documents,
        private readonly InterestCalculatorService $interestService,
    ) {}

    #[Route('/case/{id}', name: 'case_overview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(int $id): Response
    {
        $case = $this->cases->findWithOverviewRelations($id);
        if ($case === null) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

        $deadlines = $this->deadlines->findByCase($case);
        [$interestBreakdown, $breakdownError] = $this->computeBreakdown($case);
        $documents = $this->documents->findByCase($case);

        return $this->render('case/overview.html.twig', [
            'case' => $case,
            'deadlines' => $deadlines,
            'deadline_counters' => $this->countDeadlines($deadlines),
            'active_deadline' => $this->pickActiveDeadline($deadlines),
            'auditLogs' => $this->auditLogs->findByCase($case, 50),
            'portalEvents' => $this->portalEvents->findByLegalCase($case),
            'interest_breakdown' => $interestBreakdown,
            'breakdown_error' => $breakdownError,
            'documents' => $documents,
            'has_communication_proof' => $this->hasCommunicationProof($documents),
        ]);
    }

    /**
     * The first non-completed deadline strictly after now, used for the KPI "Termen activ"
     * cell and the sidebar ring. Returns null when there is no upcoming deadline.
     *
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
     * Returns `[breakdown[], errorFlag]`. The breakdown is *not* persisted — only used
     * to drive the per-period table render.
     *
     * Null when one of the calculator preconditions is missing (amount / dueDate /
     * relationshipType); `breakdownError = true` only when the calculator threw
     * (rate config missing, currency unsupported, CIVIL not supported in MVP).
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
            // Domain-shaped failures (currency unsupported / rate config missing / CIVIL not
            // supported in MVP) surface as "calculation unavailable" in the UI. Programming
            // errors (\Error, \TypeError) deliberately propagate to the Symfony handler.
            return [null, true];
        }
    }

    /**
     * Whether the case already has a `DOVADA_COMUNICARE` document attached. Drives the
     * Tab Documente sidebar warning ("Lipsește dovada comunicării") on the overview page.
     *
     * @param Document[] $documents
     */
    private function hasCommunicationProof(array $documents): bool
    {
        foreach ($documents as $document) {
            if ($document->getDocumentType() === DocumentType::DOVADA_COMUNICARE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bucket counts for the Tab Termene header ("X active · Y expirate · Z completat").
     * Computed in the controller to keep the Twig template purely presentational.
     *
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
