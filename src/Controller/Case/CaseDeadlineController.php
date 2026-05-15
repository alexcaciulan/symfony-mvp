<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Form\Deadline\AddDeadlineType;
use App\Form\Deadline\RulingCommunicationDateType;
use App\Repository\LegalCaseRepository;
use App\Repository\LegalDeadlineRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Deadline\DeadlineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pas 4.3 — gestionarea termenelor pe pagina overview dosar. Voter
 * `CASE_DEADLINE_MANAGE` ownership-only (termenele se gestionează în orice
 * stadiu, NU restricționat la AMIABIL/SOMATIE_TRIMISA/CERERE_DEPUSA).
 */
final class CaseDeadlineController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly DeadlineService $deadlineService,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/case/{caseId}/deadline/{deadlineId}/complete', name: 'case_deadline_complete', requirements: ['caseId' => '\d+', 'deadlineId' => '\d+'], methods: ['POST'])]
    public function complete(int $caseId, int $deadlineId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        if (!$this->isCsrfTokenValid('complete_deadline_' . $deadlineId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'case_overview.deadlines.flash_error_csrf');

            return $this->redirectToRoute('case_overview', ['id' => $caseId]);
        }

        $deadline = $this->deadlineRepository->find($deadlineId);
        if (!$deadline instanceof LegalDeadline || $deadline->getLegalCase()->getId() !== $case->getId()) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if ($user === null) {
            throw $this->createAccessDeniedException();
        }
        /** @var \App\Entity\User $user */
        $this->deadlineService->markCompleted($deadline, $user);

        // Turbo Stream response pentru replace in-place pe card. Pe request
        // non-Turbo (fallback no-JS / curl), facem redirect normal cu flash.
        $acceptHeader = (string) $request->headers->get('Accept', '');
        if (str_contains($acceptHeader, 'text/vnd.turbo-stream.html')) {
            $stream = $this->renderView('case/overview/_deadline_card_turbo_stream.html.twig', [
                'deadline' => $deadline,
            ]);

            return new Response($stream, Response::HTTP_OK, [
                'Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8',
            ]);
        }

        $this->addFlash('success', 'case_overview.deadlines.flash_marked_complete');

        return $this->redirectToRoute('case_overview', ['id' => $caseId]);
    }

    #[Route('/case/{caseId}/deadline/add', name: 'case_deadline_add', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function add(int $caseId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        $form = $this->createForm(AddDeadlineType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            if (!$form->isSubmitted()) {
                $this->addFlash('error', 'case_overview.deadlines.flash_error_validation');
            }

            return $this->redirectToRoute('case_overview', ['id' => $caseId]);
        }

        $data = $form->getData();
        $this->deadlineService->createHearingDeadline(
            $case,
            $data['deadlineDate'],
            $data['description'] ?? null,
        );

        $this->addFlash('success', 'case_overview.deadlines.flash_added');

        return $this->redirectToRoute('case_overview', ['id' => $caseId]);
    }

    #[Route('/case/{caseId}/ruling-communication-date', name: 'case_deadline_ruling_date', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function setRulingCommunicationDate(int $caseId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        $form = $this->createForm(RulingCommunicationDateType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            if (!$form->isSubmitted()) {
                $this->addFlash('error', 'case_overview.deadlines.flash_error_validation');
            }

            return $this->redirectToRoute('case_overview', ['id' => $caseId]);
        }

        $data = $form->getData();
        $communicationDate = $data['rulingCommunicationDate'];
        $previousDate = $case->getRulingCommunicationDate();

        $case->setRulingCommunicationDate($communicationDate);
        $this->em->flush();

        $this->auditLogService->log(
            action: 'ruling_communication_date_set',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: ['rulingCommunicationDate' => $previousDate?->format('Y-m-d')],
            newData: [
                'caseNumber' => $case->getCaseNumber(),
                'rulingCommunicationDate' => $communicationDate->format('Y-m-d'),
            ],
            category: AuditLogService::CATEGORY_DEADLINE_CREATED,
        );
        $this->em->flush();

        // Subscriber-ul Pas 4.2 nu mai poate fire pe `workflow.entered.ORDONANTA_EMISA` (tranziția s-a făcut deja) — apelăm explicit serviciul.
        if ($this->shouldTriggerAppealDeadline($case)) {
            $this->deadlineService->createAppealDeadline($case, $communicationDate);
        }

        $this->addFlash('success', 'case_overview.deadlines.flash_ruling_date_set');

        return $this->redirectToRoute('case_overview', ['id' => $caseId]);
    }

    private function findOrThrow(int $caseId): LegalCase
    {
        $case = $this->legalCaseRepository->find($caseId);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }

    private function shouldTriggerAppealDeadline(LegalCase $case): bool
    {
        if (!in_array($case->getStatus(), [CaseStatus::ORDONANTA_EMISA, CaseStatus::IN_ANULARE], true)) {
            return false;
        }

        return $this->deadlineRepository->findOneByCaseAndType($case, DeadlineType::CERERE_IN_ANULARE) === null;
    }
}
