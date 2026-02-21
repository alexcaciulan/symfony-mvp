<?php

namespace App\Controller\Admin;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CaseStatusController extends AbstractController
{
    public function __construct(
        private CaseWorkflowService $workflowService,
        private EntityManagerInterface $em,
        private AdminUrlGenerator $adminUrlGenerator,
        private AuditLogService $auditLogService,
    ) {}

    #[Route('/admin/case/{id}/change-status', name: 'admin_case_change_status', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, int $id): Response
    {
        $case = $this->em->getRepository(LegalCase::class)->find($id);

        if (!$case || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $availableTransitions = $this->workflowService->getAvailableTransitions($case);

        if ($request->isMethod('POST')) {
            $transition = $request->getPayload()->getString('transition');
            $reason = $request->getPayload()->getString('reason');

            if (!$this->isCsrfTokenValid('change-status-' . $id, $request->getPayload()->getString('_token'))) {
                $this->addFlash('danger', 'Token CSRF invalid.');

                return $this->redirectToRoute('admin_case_change_status', ['id' => $id]);
            }

            if (!\in_array($transition, $availableTransitions, true)) {
                $this->addFlash('danger', 'Tranziția nu este validă.');

                return $this->redirectToRoute('admin_case_change_status', ['id' => $id]);
            }

            $oldStatus = $case->getStatus();
            $this->workflowService->apply($case, $transition);

            $this->auditLogService->log('admin_status_change', 'LegalCase', (string) $case->getId(),
                ['status' => $oldStatus],
                [
                    'status' => $case->getStatus(),
                    'transition' => $transition,
                    'reason' => $reason ?: null,
                ]
            );

            $this->em->flush();

            $oldLabel = CaseStatus::tryFrom($oldStatus)?->label() ?? $oldStatus;
            $newLabel = CaseStatus::tryFrom($case->getStatus())?->label() ?? $case->getStatus();

            $this->addFlash('success', sprintf(
                'Statusul dosarului #%d a fost schimbat: %s → %s',
                $case->getId(),
                $oldLabel,
                $newLabel
            ));

            $url = $this->adminUrlGenerator
                ->setController(LegalCaseCrudController::class)
                ->setAction(Action::INDEX)
                ->generateUrl();

            return $this->redirect($url);
        }

        $transitionChoices = [];
        foreach ($availableTransitions as $t) {
            $transitionChoices[$t] = CaseTransition::tryFrom($t)?->label() ?? $t;
        }

        $statusLabels = [];
        foreach (CaseStatus::cases() as $status) {
            $statusLabels[$status->value] = $status->label();
        }

        return $this->render('admin/case_change_status.html.twig', [
            'case' => $case,
            'transitions' => $transitionChoices,
            'statusLabels' => $statusLabels,
        ]);
    }
}
