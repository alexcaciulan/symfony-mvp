<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\DebitAcknowledgedStatus;
use App\Enum\DocumentType;
use App\Enum\IssueSeverity;
use App\Repository\DocumentRepository;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Deadline\DeadlineService;
use App\Service\Document\CaseFilesPackager;
use App\Service\Document\MissingDocumentFileException;
use App\Service\Document\OpisGeneratorService;
use App\Service\Document\PaymentOrderRequestGeneratorService;
use App\Service\Validation\OpAdmissibilityValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Builds the payment-order petition, the index of annexes and the downloadable
 * package. Applies `genereaza_cerere` (SOMATIE_TRIMISA to CERERE_GENERATA): the
 * documents now exist, but nothing has been filed. Filing is confirmed separately
 * by the lawyer, on a channel this platform does not operate.
 * Strictly idempotent: one petition per case.
 */
final class CasePaymentOrderController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly PaymentOrderRequestGeneratorService $paymentOrderGenerator,
        private readonly OpisGeneratorService $opisGenerator,
        private readonly CaseFilesPackager $caseFilesPackager,
        private readonly CaseWorkflowService $workflowService,
        private readonly AuditLogService $auditLogService,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly DeadlineService $deadlineService,
        private readonly EntityManagerInterface $em,
        private readonly OpAdmissibilityValidator $admissibility,
    ) {}

    #[Route('/case/{id}/payment-order/generate', name: 'case_payment_order_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generate(int $id, Request $request, RateLimiterFactory $paymentOrderGenerationLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('generate_payment_order_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'case_overview.payment_order.flash_error_csrf');
        }

        if ($case->getStatus() !== CaseStatus::SOMATIE_TRIMISA) {
            return $this->respond($request, $case, false, 'error', 'case_overview.payment_order.flash_error_wrong_status');
        }

        if ($case->getCourt() === null) {
            return $this->respond($request, $case, false, 'error', 'case_overview.payment_order.flash_error_no_court');
        }

        if ($this->hasDocument($case, DocumentType::CERERE_OP)) {
            return $this->respond($request, $case, false, 'warning', 'case_overview.payment_order.flash_error_already_generated');
        }

        // Procedural prerequisite (CPC art. 1015-1016): the 15-day payment term
        // must have expired. When the real communication date is known we block if
        // it has not; otherwise the explicit consent below stands in for it.
        $payload = $request->getPayload();
        if ($case->getPaymentNoticeCommunicationDate() !== null
            && !$this->deadlineService->isPaymentTermExpired($case, new \DateTimeImmutable('today'))) {
            return $this->respond($request, $case, false, 'error', 'case_overview.payment_order.flash_error_term_not_expired');
        }

        $debitStatus = DebitAcknowledgedStatus::tryFrom($payload->getString('debitAcknowledgedStatus'));
        if ($debitStatus === null) {
            return $this->respond($request, $case, false, 'error', 'case_overview.payment_order.flash_error_debit_status_required');
        }

        if (!$payload->getBoolean('opGenerationConsent')) {
            return $this->respond($request, $case, false, 'error', 'case_overview.payment_order.flash_error_consent_required');
        }

        // Stamp duty (CPC art. 197: proof of payment is attached to the petition;
        // non-stamping annuls it). Either the proof is in, or the lawyer explicitly
        // takes the regularization route (OUG 80/2013 art. 33 alin. 2), which the
        // law allows but puts a 10-day annulment clock on the case.
        if (!$case->getStampDutyStatus()->allowsFiling()) {
            return $this->respond($request, $case, false, 'error', 'case_overview.payment_order.flash_error_stamp_duty_missing');
        }

        // Exigibility is re-checked here and not only at the wizard: this is the
        // boundary where a document leaves for the court. A claim position that is
        // not yet due makes the petition inadmissible for that sum (CPC art. 1013),
        // and a wizard POST cannot be the only thing standing between it and the
        // registry. The debtor-standing rules are deliberately not re-run here:
        // they are a condition of opening the case, already enforced at creation,
        // and a stale BPI check must not silently block a filing in progress.
        foreach ($this->admissibility->validate($case) as $issue) {
            if ($issue->severity === IssueSeverity::ERROR
                && in_array($issue->code, ['OP_DEBT_NOT_YET_DUE', 'OP_ITEM_NOT_YET_DUE'], true)) {
                return $this->respond($request, $case, false, 'error', $issue->messageKey);
            }
        }

        $user = $this->getUser();
        if ($user !== null) {
            $limiter = $paymentOrderGenerationLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume()->isAccepted()) {
                return $this->respond($request, $case, false, 'warning', 'rate_limit.payment_order_generation');
            }
        }

        $this->em->wrapInTransaction(function () use ($case, $debitStatus): void {
            $case->setDebitAcknowledgedStatus($debitStatus);
            $case->setOpGenerationConsent(true);

            $paymentOrder = $this->paymentOrderGenerator->generate($case);
            $opis = $this->opisGenerator->generate($case);
            $this->em->flush();

            $this->workflowService->apply($case, CaseTransition::GENEREAZA_CERERE->value);

            $this->auditLogService->log(
                action: 'payment_order_generated',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'paymentOrderDocumentId' => $paymentOrder->getId(),
                    'opisDocumentId' => $opis->getId(),
                    'debitAcknowledgedStatus' => $debitStatus->value,
                    'opGenerationConsent' => true,
                ],
                category: AuditLogService::CATEGORY_PAYMENT_ORDER_GENERATED,
            );
            $this->em->flush();
        });

        // Close the originating „Generează cerere OP" modal after the in-place swap.
        return $this->respond($request, $case, true, 'success', 'case_overview.payment_order.flash_success', 'hs-modal-cerere-op');
    }

    /**
     * Turbo Stream (in-place, status regions refreshed) for Turbo clients, redirect
     * + flash otherwise. `$closeModalId` dismisses the originating modal on success.
     */
    private function respond(
        Request $request,
        LegalCase $case,
        bool $updateRegions,
        string $toastVariant,
        string $toastKey,
        ?string $closeModalId = null,
    ): Response {
        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $context = $updateRegions ? $this->contextBuilder->build($case) : ['case' => $case];
            $context['update_regions'] = $updateRegions;
            $context['toast_variant'] = $toastVariant;
            $context['toast_key'] = $toastKey;
            $context['close_modal_id'] = $closeModalId;
            $context['open_modal_id'] = null;

            return new Response(
                $this->renderView('case/overview/_documents_generate_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($toastVariant, $toastKey);

        return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
    }

    #[Route('/case/{id}/zip-package/download', name: 'case_zip_package_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadZip(int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

        // ZIP-ul presupune că Document-urile generate există (cerere OP + opis +
        // somația). Dacă lipsește unul, redirectăm cu flash error — avocatul
        // trebuie să apese „Generează cerere OP" mai întâi.
        if (!$this->hasDocument($case, DocumentType::CERERE_OP)
            || !$this->hasDocument($case, DocumentType::OPIS)
            || !$this->hasDocument($case, DocumentType::SOMATIE)) {
            $this->addFlash('error', 'case_overview.zip_package.flash_error_incomplete');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        // The index lists the annexes, and annexes can still be added while the case
        // is generated but not filed. Rebuilt here so the package cannot go out with
        // a document the index does not mention, which is exactly what the petition
        // points the court to. Idempotent: the same file is overwritten in place.
        if ($case->getStatus() === CaseStatus::CERERE_GENERATA) {
            $this->opisGenerator->generate($case);
            $this->em->flush();
        }

        try {
            $zipPath = $this->caseFilesPackager->package($case);
        } catch (MissingDocumentFileException) {
            // Better an unbuildable package than one that omits a piece the petition
            // inside it says is annexed.
            $this->addFlash('error', 'case_overview.zip_package.flash_error_file_missing');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('Pachet_%s.zip', $case->getCaseNumber()),
        );
        $response->headers->set('Content-Type', 'application/zip');

        return $response;
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->legalCaseRepository->find($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }

    private function hasDocument(LegalCase $case, DocumentType $type): bool
    {
        return $this->documentRepository->findOneBy([
            'legalCase' => $case,
            'documentType' => $type,
        ]) !== null;
    }
}
