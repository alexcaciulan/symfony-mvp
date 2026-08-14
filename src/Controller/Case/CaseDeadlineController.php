<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Form\Deadline\AddDeadlineType;
use App\Form\Deadline\AnnulmentRulingCommunicationDateType;
use App\Form\Deadline\EditDeadlineType;
use App\Form\Deadline\EnforcementRegistrationNumberType;
use App\Form\Deadline\PaymentNoticeCommunicationDateType;
use App\Form\Deadline\RulingCommunicationDateType;
use App\Repository\LegalCaseRepository;
use App\Repository\LegalDeadlineRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Deadline\AgendaResponseFactory;
use App\Service\Deadline\DeadlineService;
use App\Service\Document\DocumentUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Managing the procedural deadlines of a case. The `CASE_DEADLINE_MANAGE` voter is
 * ownership only (deadlines are managed at any stage, NOT restricted to
 * AMIABIL/SOMATIE_TRIMISA/CERERE_DEPUSA).
 *
 * The same routes serve the deadlines tab of the case and the global agenda. Which
 * of the two is acting is told by the `_context` field the agenda posts; without it
 * every answer is the one the case page has always received.
 */
final class CaseDeadlineController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly DeadlineService $deadlineService,
        private readonly AuditLogService $auditLogService,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly AgendaResponseFactory $agendaResponses,
        private readonly DocumentUploadService $documentUploadService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Generic close, offered for every type EXCEPT the three that end through an act of
     * their own: the two limitation terms and the annulment window.
     *
     * A limitation period is not an act the lawyer performs and closing one is
     * irreversible, so no screen offers it any more: the agenda never did, and the
     * deadlines tab of the case stopped after the lawyer review. The annulment window is
     * refused for a different reason: it does close by a decision of the lawyer, but that
     * decision has a route of its own ({@see self::waiveAnnulmentRequest()}) so the audit
     * trail can tell it apart from a lapse and from a generic tick. Closing a ten-day
     * forfeiture term as `deadline_completed` would erase exactly that distinction.
     *
     * The guard below is what makes both rules rather than a layout: this route is shared
     * with the agenda, and a page left open in another tab, or a hand-made post, would
     * otherwise still close a term nothing can bring back.
     *
     * The platform's own closings are unaffected: they go through
     * {@see DeadlineService::closeDeadline()}, never through this route.
     */
    #[Route('/case/{caseId}/deadline/{deadlineId}/complete', name: 'case_deadline_complete', requirements: ['caseId' => '\d+', 'deadlineId' => '\d+'], methods: ['POST'])]
    public function complete(int $caseId, int $deadlineId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        if (!$this->isCsrfTokenValid('complete_deadline_' . $deadlineId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'case_overview.deadlines.flash_error_csrf');

            return $this->afterActionRedirect($request, $caseId);
        }

        $deadline = $this->deadlineRepository->find($deadlineId);
        if (!$deadline instanceof LegalDeadline || $deadline->getLegalCase()->getId() !== $case->getId()) {
            throw $this->createNotFoundException();
        }

        // Each refusal says why in its own words: the two limitation terms have no manual
        // close at all, while the annulment window has one and it lives elsewhere.
        $refusalKey = match ($deadline->getType()) {
            DeadlineType::PRESCRIPTIE, DeadlineType::PRESCRIPTIE_EXECUTARE => 'case_overview.deadlines.flash_error_limitation_no_close',
            DeadlineType::CERERE_IN_ANULARE => 'case_overview.deadlines.flash_error_annulment_no_generic_close',
            default => null,
        };
        if ($refusalKey !== null) {
            return $this->respondDeadline($request, $case, false, 'error', $refusalKey, null);
        }

        $user = $this->getUser();
        if ($user === null) {
            throw $this->createAccessDeniedException();
        }
        /** @var \App\Entity\User $user */
        $this->deadlineService->markCompleted($deadline, $user);

        // Turbo Stream response for an in-place replace of the card. On a non-Turbo
        // request (no-JS fallback / curl) a plain redirect with a flash is sent.
        if ($this->agendaResponses->wantsTurboStream($request)) {
            // Fired from the global agenda: the card this stream replaces does not
            // exist there, so the agenda gets its own regions back instead.
            if ($this->agendaResponses->isAgendaRequest($request)) {
                return $this->agendaResponses->stream($request, $user, 'success', 'case_overview.deadlines.flash_marked_complete');
            }

            $stream = $this->renderView('case/overview/_deadline_card_turbo_stream.html.twig', [
                'deadline' => $deadline,
            ]);

            return new Response($stream, Response::HTTP_OK, [
                'Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8',
            ]);
        }

        $this->addFlash('success', 'case_overview.deadlines.flash_marked_complete');

        return $this->afterActionRedirect($request, $caseId);
    }

    /**
     * The lawyer states he is not filing an annulment request, which closes the term.
     *
     * A route of its own rather than a reuse of the generic close, because the audit
     * trail has to distinguish three different endings of the same ten days: the term
     * lapsed (`appeal_term_lapsed`), the lawyer decided against filing
     * (`annulment_request_waived`), and someone ticked a generic done box
     * (`deadline_completed`). They read the same on screen and mean different things a
     * year later.
     *
     * Nothing is asked in advance and nothing is stored beyond the closing: at service
     * of the order the lawyer usually does not know yet, the ten days run either way, and
     * a lawyer who never presses this keeps the term until it closes on expiry.
     */
    #[Route('/case/{caseId}/deadline/{deadlineId}/no-annulment-request', name: 'case_deadline_no_annulment_request', requirements: ['caseId' => '\d+', 'deadlineId' => '\d+'], methods: ['POST'])]
    public function waiveAnnulmentRequest(int $caseId, int $deadlineId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        if (!$this->isCsrfTokenValid('no_annulment_request_' . $deadlineId, $request->getPayload()->getString('_token'))) {
            return $this->respondDeadline($request, $case, false, 'error', 'case_overview.deadlines.flash_error_csrf', null);
        }

        $deadline = $this->findDeadlineOrThrow($case, $deadlineId);
        if ($deadline->getType() !== DeadlineType::CERERE_IN_ANULARE) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if ($user === null) {
            throw $this->createAccessDeniedException();
        }
        /** @var \App\Entity\User $user */
        $this->deadlineService->waiveAnnulmentRequest($case, $user);

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_no_annulment_request', null);
    }

    /**
     * Records the registration number the bailiff assigned to the enforcement request,
     * which closes the enforcement-limitation term.
     *
     * The term is closed AGAINST the filing date, not against the moment the number
     * arrives: the interruption of CPC art. 708 para. 1 pt. 2 attaches to the request
     * filed and runs from its date, so anchoring on the registration would move the
     * interruption later, against the creditor. The number is what makes the closing
     * permissible, the date is what it is measured by.
     *
     * Without the filing date there is nothing to close against, so the number alone is
     * refused rather than stored: it would leave the case looking answered while the term
     * has no anchor.
     */
    #[Route('/case/{caseId}/enforcement-registration-number', name: 'case_deadline_enforcement_registration_number', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function setEnforcementRegistrationNumber(int $caseId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        $form = $this->createForm(EnforcementRegistrationNumberType::class);
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

        $requestDate = $case->getEnforcementRequestDate();
        if ($requestDate === null) {
            return $this->respondDeadline($request, $case, false, 'error', 'case_overview.deadlines.flash_error_needs_enforcement_request_date', null);
        }

        $data = $form->getData();
        $registrationNumber = trim((string) $data['enforcementRegistrationNumber']);
        $previousNumber = $case->getEnforcementRegistrationNumber();

        $case->setEnforcementRegistrationNumber($registrationNumber);

        $this->auditLogService->log(
            action: 'enforcement_registration_number_set',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: ['enforcementRegistrationNumber' => $previousNumber],
            newData: [
                'caseNumber' => $case->getCaseNumber(),
                'enforcementRequestDate' => $requestDate->format('Y-m-d'),
                'enforcementRegistrationNumber' => $registrationNumber,
            ],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
        $this->em->flush();

        // The workflow listener already ran when the case entered enforcement, without
        // this number, and closed nothing. The closing happens here instead.
        $this->deadlineService->closeExecutionPrescriptionDeadline($case, $requestDate, $registrationNumber);

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_enforcement_registration_number_set', 'hs-modal-set-enforcement-registration-number');
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

        // Single flush after the audit entry: the date and its trace reach the database
        // together, so a failure cannot leave a changed deadline anchor without a record.
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

        // The workflow subscriber can no longer fire on `workflow.entered.ORDONANTA_EMISA`
        // (the transition already happened), so the service is called explicitly.
        if ($this->shouldTriggerAppealDeadline($case)) {
            $this->deadlineService->createAppealDeadline($case, $communicationDate);
        }

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_ruling_date_set', 'hs-modal-set-ruling-communication-date');
    }

    /**
     * Records the communication of the ruling given on the annulment request. That
     * ruling made the payment order final (CPC art. 1024 para. 8), so the three years
     * of CPC art. 705 para. 1 run from this date (para. 2) and the enforcement
     * limitation term can finally be created; until then the case sits in the blockage
     * list under ANNULMENT_RULING_COMMUNICATION_MISSING.
     */
    #[Route('/case/{caseId}/annulment-ruling-communication-date', name: 'case_deadline_annulment_ruling_date', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function setAnnulmentRulingCommunicationDate(int $caseId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        $form = $this->createForm(AnnulmentRulingCommunicationDateType::class);
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
        $communicationDate = $data['annulmentRulingCommunicationDate'];
        $previousDate = $case->getAnnulmentRulingCommunicationDate();

        $case->setAnnulmentRulingCommunicationDate($communicationDate);

        $this->auditLogService->log(
            action: 'annulment_ruling_communication_date_set',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: ['annulmentRulingCommunicationDate' => $previousDate?->format('Y-m-d')],
            newData: [
                'caseNumber' => $case->getCaseNumber(),
                'annulmentRulingCommunicationDate' => $communicationDate->format('Y-m-d'),
            ],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
        $this->em->flush();

        // The workflow listener already ran without this date and created nothing, so
        // the term is created here. Enforcement having started closes that term
        // instead, which is why EXECUTARE is not in this set.
        if ($case->getStatus() === CaseStatus::DEFINITIVA) {
            $this->deadlineService->ensureExecutionPrescriptionDeadline($case, $communicationDate);
        }

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_annulment_ruling_date_set', 'hs-modal-set-annulment-ruling-communication-date');
    }

    /**
     * Records the date the debtor received the summons and, optionally, the document
     * proving it.
     *
     * The two are collected together because that is how the post office delivers them:
     * the acknowledgement arrives in the lawyer's mailbox, and the date is read off it,
     * so asking for the date now and the file later would split one act in two. Through
     * a bailiff the order is reversed, the date being known before the record is issued,
     * which is why the file stays optional. Requiring it would hold back the date, and
     * with it the 15-day term of CPC art. 1015 para. 1, for a document that changes
     * nothing about when that term started.
     *
     * The upload runs on `CASE_DEADLINE_MANAGE`, not on `CASE_UPLOAD`, by the same
     * reasoning as the stamp-duty proof (`CASE_STAMP_DUTY_MANAGE`): `CASE_UPLOAD` closes
     * once the case is registered, while these two documents are procedural pieces whose
     * moment is decided by the bailiff and the court, not by the stage the case is in.
     */
    #[Route('/case/{caseId}/summons-communication-date', name: 'case_deadline_summons_communication_date', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function setPaymentNoticeCommunicationDate(int $caseId, Request $request, RateLimiterFactory $documentUploadLimiter): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        $form = $this->createForm(PaymentNoticeCommunicationDateType::class);
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
        $communicationDate = $data['paymentNoticeCommunicationDate'];
        $method = $data['paymentNoticeCommunicationMethod'];
        $previousDate = $case->getPaymentNoticeCommunicationDate();

        $case->setPaymentNoticeCommunicationDate($communicationDate);
        $case->setPaymentNoticeCommunicationMethod($method);

        $this->auditLogService->log(
            action: 'payment_notice_communication_date_set',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: ['paymentNoticeCommunicationDate' => $previousDate?->format('Y-m-d')],
            newData: [
                'caseNumber' => $case->getCaseNumber(),
                'paymentNoticeCommunicationDate' => $communicationDate->format('Y-m-d'),
                'paymentNoticeCommunicationMethod' => $method->value,
            ],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
        $this->em->flush();

        // Recompute the 15-day RASPUNS_SOMATIE deadline from the real date and
        // drop the "estimated" disclaimer.
        $this->deadlineService->recalculatePaymentNoticeDeadline($case, $communicationDate);

        // The same date starts the six-month term of NCC art. 2540: the interruption
        // the summons produced holds only if the request is filed within it. This is
        // the only date it can be anchored on, so the term is created here.
        $this->deadlineService->createFilingDeadline($case, $communicationDate);

        // The date is already saved at this point, so a refused upload never costs the
        // term. It costs only the attachment, and the answer says so rather than
        // reporting a plain success the case does not have.
        $proof = $data['communicationProof'] ?? null;
        $user = $this->getUser();
        $toastVariant = 'success';
        $toastKey = 'case_overview.summons.modal_communication_date.flash_set';
        if ($proof instanceof UploadedFile && $user !== null) {
            if ($documentUploadLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $this->documentUploadService->upload($case, $proof, DocumentType::DOVADA_COMUNICARE, $user);
                $toastKey = 'case_overview.summons.modal_communication_date.flash_set_with_proof';
            } else {
                $toastVariant = 'warning';
                $toastKey = 'case_overview.summons.modal_communication_date.flash_set_proof_rate_limited';
            }
        }

        return $this->respondDeadline($request, $case, true, $toastVariant, $toastKey, 'hs-modal-set-summons-communication-date');
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
     * Puts the enforcement-limitation term back under watch after the enforcement that
     * closed it failed. CPC art. 708 para. 3 says the limitation is NOT interrupted
     * when the enforcement was dismissed, annulled, allowed to lapse, or abandoned by
     * the creditor, so in those cases the three years never stopped running and the
     * closing the application performed has to be undone.
     *
     * Both facts the closing rested on are cleared with it, the filing date and the
     * bailiff registration number: neither stands for anything any more, and leaving
     * either would re-close the term while describing an enforcement that did not hold.
     */
    #[Route('/case/{caseId}/enforcement-not-interrupting', name: 'case_deadline_enforcement_not_interrupting', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function reopenExecutionPrescription(int $caseId, Request $request): Response
    {
        $case = $this->findOrThrow($caseId);
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        if (!$this->isCsrfTokenValid('enforcement_not_interrupting_' . $caseId, $request->getPayload()->getString('_token'))) {
            return $this->respondDeadline($request, $case, false, 'error', 'case_overview.deadlines.flash_error_csrf', null);
        }

        $previousDate = $case->getEnforcementRequestDate();
        $previousNumber = $case->getEnforcementRegistrationNumber();
        $case->setEnforcementRequestDate(null);
        $case->setEnforcementRegistrationNumber(null);
        $this->em->flush();

        $this->auditLogService->log(
            action: 'enforcement_request_date_cleared',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: [
                'enforcementRequestDate' => $previousDate?->format('Y-m-d'),
                'enforcementRegistrationNumber' => $previousNumber,
            ],
            newData: ['caseNumber' => $case->getCaseNumber()],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
        $this->em->flush();

        $this->deadlineService->reopenExecutionPrescriptionDeadline($case, 'enforcement_did_not_interrupt');

        return $this->respondDeadline($request, $case, true, 'success', 'case_overview.deadlines.flash_execution_prescription_reopened', null);
    }

    /**
     * Turbo Stream (in-place, tab preserved) for Turbo clients, redirect + flash
     * otherwise. `$closeModalId` dismisses the originating modal on success.
     *
     * When the action came from the global agenda the stream targets that page
     * instead: `panel-termene`, `case-tabs-nav` and the rest of the case regions do
     * not exist there, so the standard stream would swap nothing at all.
     */
    private function respondDeadline(
        Request $request,
        LegalCase $case,
        bool $updateRegions,
        string $toastVariant,
        string $toastKey,
        ?string $closeModalId,
    ): Response {
        if ($this->agendaResponses->isAgendaRequest($request) && $this->agendaResponses->wantsTurboStream($request)) {
            $user = $this->getUser();
            if ($user === null) {
                throw $this->createAccessDeniedException();
            }
            /** @var \App\Entity\User $user */

            // The agenda names its dialogs after the case ones, so the id that
            // dismisses the dialog on the case page dismisses it here too.
            return $this->agendaResponses->stream($request, $user, $toastVariant, $toastKey, $closeModalId);
        }

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

        return $this->afterActionRedirect($request, (int) $case->getId());
    }

    /**
     * Where a client without Turbo lands after the action: back where it was fired
     * from. Without the agenda context this is the deadlines tab of the case, byte
     * for byte the redirect these routes have always issued.
     */
    private function afterActionRedirect(Request $request, int $caseId): Response
    {
        if ($this->agendaResponses->isAgendaRequest($request)) {
            return $this->agendaResponses->redirect($request);
        }

        return $this->redirectToRoute('case_overview', ['id' => $caseId, 'tab' => 'termene']);
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
