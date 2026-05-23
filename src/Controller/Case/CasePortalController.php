<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Enum\CaseTransition;
use App\Form\Case\PortalActivateType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Portal\CaseMonitoringService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pas 6.1 — activarea monitorizării portal.just.ro și verificarea manuală din
 * tab-ul „Activitate Portal". Voter `TRANSITION` (ownership-only): activarea
 * poate declanșa `inregistreaza_dosar`, iar `EDIT` ar bloca activarea din
 * statusuri ne-editabile (DOSAR_INREGISTRAT+).
 */
final class CasePortalController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly CaseWorkflowService $workflowService,
        private readonly CaseMonitoringService $monitoringService,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/case/{id}/portal/activate', name: 'case_portal_activate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function activate(int $id, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        $form = $this->createForm(PortalActivateType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            if (!$form->isSubmitted()) {
                $this->addFlash('error', 'case_overview.portal.flash_activate_error');
            }

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $courtCaseNumber = (string) $form->getData()['courtCaseNumber'];
        $previousNumber = $case->getCourtCaseNumber();

        $this->em->wrapInTransaction(function () use ($case, $courtCaseNumber, $previousNumber): void {
            $case->setCourtCaseNumber($courtCaseNumber);
            $case->setPortalMonitoringActive(true);
            $this->em->flush();

            // Spec Faza 3: introducerea nr. dosar din CERERE_DEPUSA înregistrează
            // dosarul. Gardat cu can() — dacă dosarul e deja înregistrat
            // (activare ulterioară), doar setăm câmpurile.
            if ($this->workflowService->can($case, CaseTransition::INREGISTREAZA_DOSAR->value)) {
                $this->workflowService->apply($case, CaseTransition::INREGISTREAZA_DOSAR->value);
            }

            $this->auditLogService->log(
                action: 'portal_activated',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                oldData: ['courtCaseNumber' => $previousNumber],
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'courtCaseNumber' => $courtCaseNumber,
                    'status' => $case->getStatus()->value,
                ],
                category: AuditLogService::CATEGORY_PORTAL_MONITORING,
            );
            $this->em->flush();
        });

        // Check sincron imediat, în afara tranzacției de activare: nu blochează
        // succesul activării dacă portalul e indisponibil.
        try {
            $this->monitoringService->monitorCase($case);
        } catch (\Throwable $e) {
            $this->logger->error('Immediate portal check after activation failed', [
                'caseId' => $case->getId(),
                'exception' => $e->getMessage(),
            ]);
        }

        $this->addFlash('success', 'case_overview.portal.flash_activated');

        return $this->redirectToRoute('case_overview', ['id' => $id]);
    }

    #[Route('/case/{id}/portal/check-now', name: 'case_portal_check_now', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function checkNow(int $id, Request $request, RateLimiterFactory $portalCheckLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('portal_check_' . $id, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'case_overview.portal.flash_activate_error');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if (!$case->isPortalMonitoringActive() || $case->getCourtCaseNumber() === null) {
            $this->addFlash('warning', 'case_overview.portal.flash_not_active');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $user = $this->getUser();
        if ($user !== null && !$portalCheckLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            $this->addFlash('warning', 'rate_limit.portal_check');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        try {
            $this->monitoringService->monitorCase($case);
            $this->addFlash('success', 'case_overview.portal.flash_check_done');
        } catch (\Throwable $e) {
            $this->logger->error('Manual portal check failed', [
                'caseId' => $case->getId(),
                'exception' => $e->getMessage(),
            ]);
            $this->addFlash('error', 'case_overview.portal.flash_check_error');
        }

        return $this->redirectToRoute('case_overview', ['id' => $id]);
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->legalCaseRepository->find($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }
}
