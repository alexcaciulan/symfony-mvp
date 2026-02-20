<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\LegalCase;
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
    private const STATUS_LABELS = [
        'draft' => 'Ciornă',
        'pending_payment' => 'În așteptarea plății',
        'paid' => 'Plătit',
        'submitted_to_court' => 'Trimis la instanță',
        'under_review' => 'În analiză',
        'additional_info_requested' => 'Info suplimentare',
        'resolved_accepted' => 'Admis',
        'resolved_rejected' => 'Respins',
        'enforcement' => 'Executare silită',
    ];

    private const TRANSITION_LABELS = [
        'submit' => 'Depune (draft → așteptare plată)',
        'confirm_payment' => 'Confirmă plata',
        'submit_to_court' => 'Trimite la instanță',
        'mark_received' => 'Marchează recepționat',
        'request_info' => 'Solicită informații',
        'provide_info' => 'Informații furnizate',
        'accept' => 'Admite',
        'reject' => 'Respinge',
        'enforce' => 'Executare silită',
    ];

    public function __construct(
        private CaseWorkflowService $workflowService,
        private EntityManagerInterface $em,
        private AdminUrlGenerator $adminUrlGenerator,
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

            $auditLog = new AuditLog();
            $auditLog->setUser($this->getUser());
            $auditLog->setAction('admin_status_change');
            $auditLog->setEntityType('LegalCase');
            $auditLog->setEntityId((string) $case->getId());
            $auditLog->setOldData(['status' => $oldStatus]);
            $auditLog->setNewData([
                'status' => $case->getStatus(),
                'transition' => $transition,
                'reason' => $reason ?: null,
            ]);
            $auditLog->setIpAddress($request->getClientIp());
            $this->em->persist($auditLog);

            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Statusul dosarului #%d a fost schimbat: %s → %s',
                $case->getId(),
                self::STATUS_LABELS[$oldStatus] ?? $oldStatus,
                self::STATUS_LABELS[$case->getStatus()] ?? $case->getStatus()
            ));

            $url = $this->adminUrlGenerator
                ->setController(LegalCaseCrudController::class)
                ->setAction(Action::INDEX)
                ->generateUrl();

            return $this->redirect($url);
        }

        $transitionChoices = [];
        foreach ($availableTransitions as $t) {
            $transitionChoices[$t] = self::TRANSITION_LABELS[$t] ?? $t;
        }

        return $this->render('admin/case_change_status.html.twig', [
            'case' => $case,
            'transitions' => $transitionChoices,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }
}
