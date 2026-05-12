<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Repository\AuditLogRepository;
use App\Repository\CourtPortalEventRepository;
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

        return $this->render('case/overview.html.twig', [
            'case' => $case,
            'deadlines' => $deadlines,
            'active_deadline' => $this->pickActiveDeadline($deadlines),
            'auditLogs' => $this->auditLogs->findByCase($case, 50),
            'portalEvents' => $this->portalEvents->findByLegalCase($case),
            'interest_breakdown' => $interestBreakdown,
            'breakdown_error' => $breakdownError,
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
}
