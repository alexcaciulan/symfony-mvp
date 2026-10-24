<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\DTO\Document\PowerOfAttorneyDetails;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Form\Case\DocumentUploadType;
use App\Form\Case\PowerOfAttorneyDetailsType;
use App\Repository\DocumentRepository;
use App\Repository\LegalCaseRepository;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Document\DocumentUploadService;
use App\Service\Document\PowerOfAttorneyService;
use Symfony\Component\Form\FormInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/case')]
final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentUploadService $documentUploadService,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly PowerOfAttorneyService $powerOfAttorneyService,
        private readonly string $uploadsDir,
    ) {}

    #[Route('/{caseId}/document/{documentId}/download', name: 'case_document_download', requirements: ['caseId' => '\d+', 'documentId' => '\d+'], methods: ['GET'])]
    public function download(int $caseId, int $documentId): Response
    {
        $legalCase = $this->legalCaseRepository->find($caseId);

        if (!$legalCase || $legalCase->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('CASE_VIEW', $legalCase);

        $document = $this->documentRepository->find($documentId);

        if (!$document || $document->getLegalCase()?->getId() !== $legalCase->getId()) {
            throw $this->createNotFoundException();
        }

        $filePath = $this->uploadsDir . '/' . $document->getStoredFilename();

        if (!file_exists($filePath)) {
            $this->addFlash('error', 'document.download.file_missing');

            return $this->redirectToRoute('case_overview', ['id' => $caseId]);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getOriginalFilename()
        );

        return $response;
    }

    #[Route('/{caseId}/document/upload', name: 'case_document_upload', requirements: ['caseId' => '\d+'], methods: ['POST'])]
    public function upload(Request $request, int $caseId, RateLimiterFactory $documentUploadLimiter): Response
    {
        $legalCase = $this->legalCaseRepository->find($caseId);

        if (!$legalCase || $legalCase->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('CASE_UPLOAD', $legalCase);

        $limiter = $documentUploadLimiter->create($this->getUser()->getUserIdentifier());
        if (!$limiter->consume()->isAccepted()) {
            return $this->respondDocument($request, $legalCase, false, 'warning', 'rate_limit.document_upload', null);
        }

        $form = $this->createForm(DocumentUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->respondDocument($request, $legalCase, false, 'error', $this->firstErrorKey($form, 'document.upload.error'), null);
        }

        $data = $form->getData();
        $documentType = DocumentType::from($data['documentType']);

        // The cap counts, and applies to, the evidence the lawyer chose to annex. The
        // two proofs the procedure imposes are exempt on both sides of the comparison:
        // a case that filled its quota with invoices must still be able to attach the
        // proof of communication, which the petition cannot be generated without.
        if (!$documentType->isProceduralRequirement()
            && $this->documentRepository->countCappedAttachments($legalCase) >= 10) {
            return $this->respondDocument($request, $legalCase, false, 'error', 'document.upload.max_files_reached', null);
        }

        $powerOfAttorney = $documentType === DocumentType::IMPUTERNICIRE_AVOCATIALA
            ? $this->powerOfAttorneyDetailsFrom($data)
            : null;

        $document = $this->documentUploadService->upload(
            $legalCase,
            $data['file'],
            $documentType,
            $this->getUser(),
            powerOfAttorney: $powerOfAttorney,
        );

        return $this->respondDocument(
            $request,
            $legalCase,
            true,
            'success',
            'document.upload.success',
            'hs-modal-upload-document',
            $this->afterSummonsWarnings($document, $legalCase, 'document.upload.power_of_attorney_after_summons'),
        );
    }

    /**
     * Completes or corrects the series, number and date of a power of attorney
     * already on the case, for acts uploaded before these were asked for.
     */
    #[Route('/{caseId}/document/{documentId}/power-of-attorney', name: 'case_document_power_of_attorney', requirements: ['caseId' => '\d+', 'documentId' => '\d+'], methods: ['POST'])]
    public function updatePowerOfAttorney(Request $request, int $caseId, int $documentId): Response
    {
        $legalCase = $this->legalCaseRepository->find($caseId);

        if (!$legalCase || $legalCase->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('CASE_UPLOAD', $legalCase);

        $document = $this->documentRepository->find($documentId);

        if (!$document
            || $document->getLegalCase()?->getId() !== $legalCase->getId()
            || $document->getDocumentType() !== DocumentType::IMPUTERNICIRE_AVOCATIALA) {
            throw $this->createNotFoundException();
        }

        if (!$this->powerOfAttorneyService->canEditDetails($legalCase)) {
            return $this->respondDocument($request, $legalCase, false, 'error', 'document.power_of_attorney.locked', null);
        }

        $form = $this->createForm(PowerOfAttorneyDetailsType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->respondDocument($request, $legalCase, false, 'error', $this->firstErrorKey($form, 'document.power_of_attorney.error'), null);
        }

        $this->powerOfAttorneyService->updateDetails($document, $this->powerOfAttorneyDetailsFrom($form->getData()));

        $extraToasts = $this->afterSummonsWarnings($document, $legalCase, 'document.power_of_attorney.after_summons');
        // The index already generated lists the old values; the package download
        // rebuilds it, so say when that happens rather than leave it stale silently.
        if ($legalCase->getStatus() === CaseStatus::CERERE_GENERATA) {
            $extraToasts[] = ['key' => 'document.power_of_attorney.opis_rebuilt_on_download', 'params' => [], 'variant' => 'info'];
        }

        return $this->respondDocument(
            $request,
            $legalCase,
            true,
            'success',
            'document.power_of_attorney.updated',
            'hs-modal-power-of-attorney',
            $extraToasts,
        );
    }

    #[Route('/{caseId}/document/{documentId}/delete', name: 'case_document_delete', requirements: ['caseId' => '\d+', 'documentId' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $caseId, int $documentId): Response
    {
        $legalCase = $this->legalCaseRepository->find($caseId);

        if (!$legalCase || $legalCase->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('CASE_UPLOAD', $legalCase);

        $document = $this->documentRepository->find($documentId);

        if (!$document || $document->getLegalCase()?->getId() !== $legalCase->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete-document-' . $documentId, $request->getPayload()->getString('_token'))) {
            return $this->respondDocument($request, $legalCase, false, 'error', 'document.delete.invalid_csrf', null);
        }

        // The stamp-duty proof carries payment state and decides what the petition
        // claims about it. Deleting it would leave the case reading as paid with no
        // proof, which the petition then describes as a payment made through the
        // electronic registry: a statement to the court about a payment route nobody
        // took. It is removed through its own flow or not at all.
        if ($document->getDocumentType() === DocumentType::DOVADA_TAXA_TIMBRU) {
            return $this->respondDocument($request, $legalCase, false, 'error', 'document.delete.stamp_duty_proof_protected', null);
        }

        // The UI never offers the action for these types; this guards direct requests.
        if ($document->getDocumentType()->isAutoGenerated()) {
            return $this->respondDocument($request, $legalCase, false, 'error', 'document.delete.generated_protected', null);
        }

        if (!$document->getDocumentType()->isDeletable()) {
            return $this->respondDocument($request, $legalCase, false, 'error', 'document.delete.evidence_protected', null);
        }

        $this->documentUploadService->delete($document);

        return $this->respondDocument($request, $legalCase, true, 'success', 'document.delete.success', null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function powerOfAttorneyDetailsFrom(array $data): PowerOfAttorneyDetails
    {
        // Both forms refuse a power of attorney without a date before reaching here.
        $date = $data['powerOfAttorneyDate'] ?? null;
        if (!$date instanceof \DateTimeImmutable) {
            throw new \LogicException('The power of attorney date is validated by the form.');
        }

        return new PowerOfAttorneyDetails(
            $data['powerOfAttorneySeries'] ?? null,
            $data['powerOfAttorneyNumber'] ?? null,
            $date,
        );
    }

    /**
     * @param string $key the upload and the edit word the warning differently: only the
     *                    upload brought a new act onto the case
     *
     * @return list<array{key: string, params: array<string, string>, variant: string}>
     */
    private function afterSummonsWarnings(Document $document, LegalCase $case, string $key): array
    {
        if (!$this->powerOfAttorneyService->dateIsAfterSummons($document, $case)) {
            return [];
        }

        return [[
            'key' => $key,
            'params' => [
                '%poa_date%' => $document->getPowerOfAttorneyDate()?->format('d.m.Y') ?? '',
                '%summons_date%' => $case->getPaymentNoticeDate()?->format('d.m.Y') ?? '',
            ],
            'variant' => 'warning',
        ]];
    }

    private function firstErrorKey(FormInterface $form, string $fallback): string
    {
        foreach ($form->getErrors(true) as $error) {
            return $error->getMessage();
        }

        return $fallback;
    }

    /**
     * Turbo Stream (in-place, tab preserved) for Turbo clients, redirect + flash
     * otherwise. `$closeModalId` dismisses the originating modal on success.
     *
     * @param list<array{key: string, params: array<string, string>, variant: string}> $extraToasts
     */
    private function respondDocument(
        Request $request,
        LegalCase $case,
        bool $updateRegions,
        string $toastVariant,
        string $toastKey,
        ?string $closeModalId,
        array $extraToasts = [],
    ): Response {
        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $context = $updateRegions ? $this->contextBuilder->build($case) : ['case' => $case];
            $context['update_regions'] = $updateRegions;
            $context['toast_variant'] = $toastVariant;
            $context['toast_key'] = $toastKey;
            $context['close_modal_id'] = $closeModalId;
            $context['extra_toasts'] = $extraToasts;

            return new Response(
                $this->renderView('case/overview/_documents_actions_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($toastVariant, $toastKey);
        foreach ($extraToasts as $toast) {
            $this->addFlash('toast.' . $toast['variant'], ['key' => $toast['key'], 'params' => $toast['params']]);
        }

        return $this->redirectToRoute('case_overview', ['id' => $case->getId(), 'tab' => 'documente']);
    }
}
