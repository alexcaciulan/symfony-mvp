<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\CloseReason;
use App\Enum\DocumentType;
use App\Enum\RejectReason;
use App\Form\Case\CloseCaseType;
use App\Form\Case\ConfirmFilingType;
use App\Form\Case\IssueRulingType;
use App\Form\Case\RegisterCaseNumberType;
use App\Form\Case\RejectCaseType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Document\DocumentUploadService;
use App\Util\PiiMasker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private readonly DocumentUploadService $documentUploadService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * The lawyer declares that the petition has left for the court. The platform
     * files nothing itself, so this is the only signal that anything reached the
     * registry, and several downstream rules hang on it: the six-month term of
     * NCC art. 2540 and the stamp-duty follow-up both start from filing, not from
     * the moment the PDF was produced.
     */
    #[Route('/case/{id}/transition/file', name: 'case_transition_file', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirmFiling(Request $request, int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        $form = $this->createForm(ConfirmFilingType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $firstError = null;
            foreach ($form->getErrors(true) as $error) {
                $firstError = $error;
                break;
            }
            $this->addFlash('error', $firstError?->getMessage() ?? 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($case->getStatus() !== CaseStatus::CERERE_GENERATA) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $data = $form->getData();
        $filedAt = $data['filedAt'];
        $channel = $data['filingChannel'];
        $reference = $data['filingReference'] ?? null;

        $this->em->wrapInTransaction(function () use ($case, $filedAt, $channel, $reference): void {
            $case->setFiledAt($filedAt);
            $case->setFilingChannel($channel);
            $case->setFilingReference($reference);
            $this->workflowService->apply($case, CaseTransition::DEPUNE_CERERE->value);

            $this->em->flush();

            $this->auditLogService->log(
                action: 'case_filed',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'filedAt' => $filedAt->format('Y-m-d'),
                    'filingChannel' => $channel->value,
                    'filingReference' => $reference,
                ],
                category: AuditLogService::CATEGORY_CASE_FILED,
            );
            $this->em->flush();
        });

        return $this->respondAfterTransition($case, 'case_overview.transition.flash_success_filed');
    }

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

        // Both origins are legitimate: an ECRIS number is itself proof the petition
        // reached the court, so it must not be refused merely because the lawyer
        // skipped confirming the filing.
        if (!in_array($case->getStatus(), [CaseStatus::CERERE_GENERATA, CaseStatus::CERERE_DEPUSA], true)) {
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

        // Optionally attach the issued order document (CPC art. 1020). Best-effort
        // after the transition: a failed upload (disk/IO) must not undo the issued
        // ruling, which is already committed. The lawyer can attach it later.
        $rulingDocument = $form->get('rulingDocument')->getData();
        $user = $this->getUser();
        if ($rulingDocument instanceof UploadedFile && $user !== null) {
            try {
                $this->documentUploadService->upload($case, $rulingDocument, DocumentType::ORDONANTA_PLATA, $user);
            } catch (\Throwable) {
                $this->addFlash('warning', 'case_overview.transition.flash_warning_ruling_document');
            }
        }

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
            CaseStatus::IN_ANULARE, CaseStatus::EXECUTARE => 'admite_cerere_anulare',
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

        if (!in_array($case->getStatus(), [CaseStatus::DEFINITIVA, CaseStatus::EXECUTARE], true)) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $reason = $form->get('reason')->getData();
        if (!$reason instanceof CloseReason) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        // Insolvency is an enforcement-phase finding: this reason is only valid
        // from EXECUTARE, never as a direct OP closure from DEFINITIVA.
        if ($reason === CloseReason::INSOLVENT_EXECUTARE && $case->getStatus() !== CaseStatus::EXECUTARE) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $details = (string) ($form->get('details')->getData() ?? '');
        $transition = $reason->targetTransition();
        $fromStatus = $case->getStatus()->value;

        if (!$this->workflowService->can($case, $transition)) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

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

    #[Route('/case/{id}/transition/executare', name: 'case_transition_executare', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function transitionToExecution(Request $request, int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('transition_executare', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if (!$this->workflowService->can($case, 'trece_la_executare')) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        // Starting enforcement from ORDONANTA_EMISA before the order has been
        // communicated would make the 10-day annulment window ambiguous, so the
        // communication date is required from that status.
        if ($case->getStatus() === CaseStatus::ORDONANTA_EMISA && $case->getRulingCommunicationDate() === null) {
            $this->addFlash('error', 'case_overview.transition.flash_error_needs_communication_date');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        // The bailiff needs the payment-order document to open enforcement, so it
        // must be attached before entering the enforcement phase (lawyer requirement).
        $hasRulingDocument = $case->getDocuments()->exists(
            static fn (int $_key, Document $doc): bool => $doc->getDocumentType() === DocumentType::ORDONANTA_PLATA,
        );
        if (!$hasRulingDocument) {
            $this->addFlash('error', 'case_overview.transition.flash_error_needs_ruling_document');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $fromStatus = $case->getStatus()->value;
        $annulmentPending = $case->getStatus() === CaseStatus::IN_ANULARE;

        $this->em->wrapInTransaction(function () use ($case, $fromStatus, $annulmentPending): void {
            $this->workflowService->apply($case, 'trece_la_executare');

            $this->em->flush();

            $this->auditLogService->log(
                action: 'execution_started',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'courtCaseNumber' => $case->getCourtCaseNumber(),
                    'fromStatus' => $fromStatus,
                    'annulmentPending' => $annulmentPending,
                ],
                category: AuditLogService::CATEGORY_EXECUTION_STARTED,
            );
            $this->em->flush();
        });

        return $this->respondAfterTransition($case, 'case_overview.transition.flash_success_executare');
    }

    #[Route('/case/{id}/transition/annulment-rejected', name: 'case_transition_annulment_rejected', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function annulmentRejected(Request $request, int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('transition_annulment_rejected', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'case_overview.transition.flash_error_validation');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        // The annulment was dismissed, so the order stands. From IN_ANULARE the
        // case becomes final (DEFINITIVA); from EXECUTARE enforcement was already
        // running and continues (self-loop).
        $transition = match ($case->getStatus()) {
            CaseStatus::IN_ANULARE => 'respinge_cerere_anulare',
            CaseStatus::EXECUTARE => 'respinge_cerere_anulare_executare',
            default => null,
        };

        if ($transition === null || !$this->workflowService->can($case, $transition)) {
            $this->addFlash('error', 'case_overview.transition.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $fromStatus = $case->getStatus()->value;

        $this->em->wrapInTransaction(function () use ($case, $transition, $fromStatus): void {
            $this->workflowService->apply($case, $transition);

            $this->em->flush();

            $this->auditLogService->log(
                action: 'annulment_rejected',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'courtCaseNumber' => $case->getCourtCaseNumber(),
                    'fromStatus' => $fromStatus,
                    'transition' => $transition,
                ],
                category: AuditLogService::CATEGORY_ANNULMENT_REJECTED,
            );
            $this->em->flush();
        });

        return $this->respondAfterTransition($case, 'case_overview.transition.flash_success_annulment_rejected');
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
