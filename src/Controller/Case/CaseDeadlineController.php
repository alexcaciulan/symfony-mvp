<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Form\Deadline\AddDeadlineType;
use App\Form\Deadline\EditDeadlineType;
use App\Form\Deadline\RulingCommunicationDateType;
use App\Repository\LegalCaseRepository;
use App\Repository\LegalDeadlineRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\OverviewContextBuilder;
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
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/case/{caseId}/deadline/{deadlineId}/complete', name: 'case_deadline_complete', requirements: ['caseId' => '\d+', 'deadlineId' => '\d+'], methods: ['POST'])]
    public function complete(int $caseId, int $deadlineId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        if (!$this->isCsrfTokenValid('complete_deadline_' . $deadlineId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'case_overview.deadlines.flash_error_csrf');

            return $this->redirectToRoute('case_overview', ['id' => $caseId, 'tab' => 'termene']);
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

        return $this->redirectToRoute('case_overview', ['id' => $caseId, 'tab' => 'termene']);
    }

    #[Route('/case/{caseId}/deadline/add', name: 'case_deadline_add', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function add(int $caseId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        $form = $this->createForm(AddDeadlineType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $firstError = null;
            foreach ($form->getErrors(true) as $error) {
                $firstError = $error;
                break;
            }
            $toastKey = $firstError?->getMessage() ?? 'case_overview.deadlines.flash_error_validation';

            return $this->respondDeadline($request, $case, false, 'error', $toastKey, null);
        }

        $data = $form->getData();
        $this->deadlineService->createCustomDeadline(
            $case,
            $data['deadlineDate'],
            $data['description'] ?? null,
        );

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_added', 'hs-modal-add-deadline');
    }

    #[Route('/case/{caseId}/ruling-communication-date', name: 'case_deadline_ruling_date', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function setRulingCommunicationDate(int $caseId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        $form = $this->createForm(RulingCommunicationDateType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $firstError = null;
            foreach ($form->getErrors(true) as $error) {
                $firstError = $error;
                break;
            }
            $toastKey = $firstError?->getMessage() ?? 'case_overview.deadlines.flash_error_validation';

            return $this->respondDeadline($request, $case, false, 'error', $toastKey, null);
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

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_ruling_date_set', 'hs-modal-set-ruling-communication-date');
    }

    #[Route('/case/{caseId}/deadline/{deadlineId}/edit', name: 'case_deadline_edit', requirements: ['caseId' => '\d+', 'deadlineId' => '\d+'], methods: ['POST'])]
    public function edit(int $caseId, int $deadlineId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);
        $deadline = $this->findDeadlineOrThrow($case, $deadlineId);

        // A completed deadline is a closed record: editing it is meaningless.
        if ($deadline->isCompleted()) {
            return $this->respondDeadline($request, $case, false, 'error', 'case_overview.deadlines.flash_error_completed_no_edit', null);
        }

        $form = $this->createForm(EditDeadlineType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $firstError = null;
            foreach ($form->getErrors(true) as $error) {
                $firstError = $error;
                break;
            }
            $toastKey = $firstError?->getMessage() ?? 'case_overview.deadlines.flash_error_validation';

            return $this->respondDeadline($request, $case, false, 'error', $toastKey, null);
        }

        $data = $form->getData();
        $oldData = [
            'type' => $deadline->getType()->value,
            'deadlineDate' => $deadline->getDeadlineDate()->format('Y-m-d'),
            'description' => $deadline->getDescription(),
        ];

        $dateChanged = $deadline->getDeadlineDate()->format('Y-m-d') !== $data['deadlineDate']->format('Y-m-d');
        $deadline->setDeadlineDate($data['deadlineDate']);
        $deadline->setDescription($data['description'] ?? null);
        // The date moved, so a reminder already sent for the old date must not
        // suppress the ones for the new date (e.g. the critical 10-day appeal term).
        if ($dateChanged) {
            $deadline->resetAlertFlags();
        }
        $this->em->flush();

        $this->auditLogService->log(
            action: 'deadline_edited',
            entityType: LegalDeadline::class,
            entityId: (string) $deadline->getId(),
            oldData: $oldData,
            newData: [
                'type' => $deadline->getType()->value,
                'deadlineDate' => $deadline->getDeadlineDate()->format('Y-m-d'),
                'description' => $deadline->getDescription(),
            ],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
        $this->em->flush();

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_edited', 'hs-modal-edit-deadline');
    }

    #[Route('/case/{caseId}/deadline/{deadlineId}/delete', name: 'case_deadline_delete', requirements: ['caseId' => '\d+', 'deadlineId' => '\d+'], methods: ['POST'])]
    public function delete(int $caseId, int $deadlineId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        if (!$this->isCsrfTokenValid('delete_deadline_' . $deadlineId, $request->getPayload()->getString('_token'))) {
            return $this->respondDeadline($request, $case, false, 'error', 'case_overview.deadlines.flash_error_csrf', null);
        }

        $deadline = $this->findDeadlineOrThrow($case, $deadlineId);

        $this->auditLogService->log(
            action: 'deadline_deleted',
            entityType: LegalDeadline::class,
            entityId: (string) $deadline->getId(),
            oldData: [
                'type' => $deadline->getType()->value,
                'deadlineDate' => $deadline->getDeadlineDate()->format('Y-m-d'),
                'description' => $deadline->getDescription(),
            ],
            newData: ['caseNumber' => $case->getCaseNumber()],
            category: AuditLogService::CATEGORY_DEADLINE_DELETED,
        );

        $this->em->remove($deadline);
        $this->em->flush();

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_deleted', null);
    }

    /**
     * Turbo Stream (in-place, tab preserved) for Turbo clients, redirect + flash
     * otherwise. `$closeModalId` dismisses the originating modal on success.
     */
    private function respondDeadline(
        Request $request,
        LegalCase $case,
        bool $updateRegions,
        string $toastVariant,
        string $toastKey,
        ?string $closeModalId,
    ): Response {
        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $context = $updateRegions ? $this->contextBuilder->build($case) : ['case' => $case];
            $context['update_regions'] = $updateRegions;
            $context['toast_variant'] = $toastVariant;
            $context['toast_key'] = $toastKey;
            $context['close_modal_id'] = $closeModalId;

            return new Response(
                $this->renderView('case/overview/_termene_actions_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($toastVariant, $toastKey);

        return $this->redirectToRoute('case_overview', ['id' => $case->getId(), 'tab' => 'termene']);
    }

    private function findOrThrow(int $caseId): LegalCase
    {
        $case = $this->legalCaseRepository->find($caseId);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }

    private function findDeadlineOrThrow(LegalCase $case, int $deadlineId): LegalDeadline
    {
        $deadline = $this->deadlineRepository->find($deadlineId);
        if (!$deadline instanceof LegalDeadline || $deadline->getLegalCase()->getId() !== $case->getId()) {
            throw $this->createNotFoundException();
        }

        return $deadline;
    }

    private function shouldTriggerAppealDeadline(LegalCase $case): bool
    {
        if (!in_array($case->getStatus(), [CaseStatus::ORDONANTA_EMISA, CaseStatus::IN_ANULARE], true)) {
            return false;
        }

        return $this->deadlineRepository->findOneByCaseAndType($case, DeadlineType::CERERE_IN_ANULARE) === null;
    }
}
