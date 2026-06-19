<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\CloseReason;
use App\Enum\RejectReason;
use App\Form\Case\CloseCaseType;
use App\Form\Case\IssueRulingType;
use App\Form\Case\RegisterCaseNumberType;
use App\Form\Case\RejectCaseType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Util\PiiMasker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Manual workflow transitions invoked from the case overview hero,
 * dropdown and recommended actions. Mirrors the established pattern
 * (CasePaymentOrderController): voter, form-validated CSRF, status
 * guard, transactional apply+audit, then redirect with a flash.
 */
final class CaseTransitionController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly CaseWorkflowService $workflowService,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/case/{id}/transition/register', name: 'case_transition_register', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function register(Request $request, int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        $form = $this->createForm(RegisterCaseNumberType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($case->getStatus() !== CaseStatus::CERERE_DEPUSA) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $courtCaseNumber = (string) $form->get('courtCaseNumber')->getData();
        $fromStatus = $case->getStatus()->value;

        $this->em->wrapInTransaction(function () use ($case, $courtCaseNumber, $fromStatus): void {
            $case->setCourtCaseNumber($courtCaseNumber);
            $this->workflowService->apply($case, 'inregistreaza_dosar');

            $this->em->flush();

            $this->auditLogService->log(
                action: 'case_registered',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'courtCaseNumber' => $courtCaseNumber,
                    'fromStatus' => $fromStatus,
                ],
                category: AuditLogService::CATEGORY_CASE_REGISTERED,
            );
            $this->em->flush();
        });

        return $this->respondAfterTransition($case, 'case_overview.transition.flash_success_register');
    }

    #[Route('/case/{id}/transition/issue-ruling', name: 'case_transition_issue_ruling', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function issueRuling(Request $request, int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        $form = $this->createForm(IssueRulingType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($case->getStatus() !== CaseStatus::TERMEN_FIXAT) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $rulingDate = $form->get('rulingDate')->getData();
        if (!$rulingDate instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        // LegalCase.finalRulingDate is mapped as DATE_MUTABLE — convert the
        // immutable form value to a mutable DateTime to match the Doctrine
        // column type. Same pattern as CaseWizardController for dueDate.
        $rulingDateMutable = \DateTime::createFromImmutable($rulingDate);
        $fromStatus = $case->getStatus()->value;

        $this->em->wrapInTransaction(function () use ($case, $rulingDateMutable, $rulingDate, $fromStatus): void {
            $case->setFinalRulingDate($rulingDateMutable);
            $this->workflowService->apply($case, 'emite_ordonanta');

            $this->em->flush();

            $this->auditLogService->log(
                action: 'ruling_issued',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'courtCaseNumber' => $case->getCourtCaseNumber(),
                    'rulingDate' => $rulingDate->format('Y-m-d'),
                    'fromStatus' => $fromStatus,
                ],
                category: AuditLogService::CATEGORY_RULING_ISSUED,
            );
            $this->em->flush();
        });

        return $this->respondAfterTransition($case, 'case_overview.transition.flash_success_ruling');
    }

    #[Route('/case/{id}/transition/reject', name: 'case_transition_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(Request $request, int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        $form = $this->createForm(RejectCaseType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $transition = match ($case->getStatus()) {
            CaseStatus::TERMEN_FIXAT => 'respinge',
            CaseStatus::IN_ANULARE => 'admite_cerere_anulare',
            default => null,
        };

        if ($transition === null) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $reason = $form->get('reason')->getData();
        $details = (string) ($form->get('details')->getData() ?? '');
        $fromStatus = $case->getStatus()->value;

        if (!$reason instanceof RejectReason) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $this->em->wrapInTransaction(function () use ($case, $transition, $reason, $details, $fromStatus): void {
            $this->workflowService->apply($case, $transition);

            $this->em->flush();

            // Defense-in-depth GDPR: the free-text `details` field could end up
            // containing a CNP if the lawyer pastes case-file text. Mask before
            // persisting to the audit log.
            $newData = PiiMasker::maskCnpInArray([
                'caseNumber' => $case->getCaseNumber(),
                'reason' => $reason->value,
                'details' => $details,
                'fromStatus' => $fromStatus,
                'transition' => $transition,
            ]);

            $this->auditLogService->log(
                action: 'case_rejected',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: $newData,
                category: AuditLogService::CATEGORY_CASE_REJECTED,
            );
            $this->em->flush();
        });

        return $this->respondAfterTransition($case, 'case_overview.transition.flash_success_reject');
    }

    #[Route('/case/{id}/transition/close', name: 'case_transition_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(Request $request, int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        $form = $this->createForm(CloseCaseType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($case->getStatus() !== CaseStatus::DEFINITIVA) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $reason = $form->get('reason')->getData();
        if (!$reason instanceof CloseReason) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $details = (string) ($form->get('details')->getData() ?? '');
        $transition = $reason->targetTransition();
        $fromStatus = $case->getStatus()->value;

        $this->em->wrapInTransaction(function () use ($case, $transition, $reason, $details, $fromStatus): void {
            $this->workflowService->apply($case, $transition);

            $this->em->flush();

            // Defense-in-depth GDPR: see reject() — same justification.
            $newData = PiiMasker::maskCnpInArray([
                'caseNumber' => $case->getCaseNumber(),
                'reason' => $reason->value,
                'details' => $details,
                'fromStatus' => $fromStatus,
                'transition' => $transition,
            ]);

            $this->auditLogService->log(
                action: 'case_closed',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: $newData,
                category: AuditLogService::CATEGORY_CASE_CLOSED,
            );
            $this->em->flush();
        });

        return $this->respondAfterTransition($case, 'case_overview.transition.flash_success_close');
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->cases->findWithOverviewRelations($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }

    /**
     * Decides between Turbo Stream multi-fragment response and a classic
     * redirect based on the Accept header. Mirrors the detection logic
     * from {@see \App\Controller\Case\CaseDeadlineController::complete}.
     */
    private function respondAfterTransition(LegalCase $case, string $successKey): Response
    {
        // Always redirect. Turbo Drive follows the redirect and re-renders the
        // page, so the Preline modal the form was submitted from is gone and the
        // success flash shows exactly once. A Turbo Stream response (replacing
        // fragments in place) would leave the modal open and the unconsumed flash
        // would leak onto the next full page load.
        $this->addFlash('success', $successKey);

        return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
    }
}
