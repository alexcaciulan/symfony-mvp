<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Repository\AuditLogRepository;
use App\Repository\CourtPortalEventRepository;
use App\Repository\LegalCaseRepository;
use App\Repository\LegalDeadlineRepository;
use App\Security\Voter\CaseVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pas 4.0 — Pagina overview dosar.
 *
 * Foundation pentru cele 5 tab-uri (Detalii / Documente / Termene / Activitate Portal / Audit)
 * livrate progresiv în sub-pașii 4.0.2 → 4.0.7. La 4.0.1 livrăm shell-ul minim funcțional
 * (route + voter check + data fetch + template cu 5 panels stub).
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
    ) {}

    #[Route('/case/{id}', name: 'case_overview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(int $id): Response
    {
        $case = $this->cases->find($id);
        if ($case === null) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

        return $this->render('case/overview.html.twig', [
            'case' => $case,
            'deadlines' => $this->deadlines->findByCase($case),
            'auditLogs' => $this->auditLogs->findByCase($case, 50),
            'portalEvents' => $this->portalEvents->findByLegalCase($case),
        ]);
    }
}
