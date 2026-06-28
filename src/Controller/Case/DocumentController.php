<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Enum\DocumentType;
use App\Form\Case\DocumentUploadType;
use App\Repository\DocumentRepository;
use App\Repository\LegalCaseRepository;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Document\DocumentUploadService;
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

        // Cap only source attachments — the application-generated SOMATIE / CERERE_OP /
        // OPIS must not consume the lawyer's upload quota.
        $documentCount = $this->documentRepository->countSourceByCase($legalCase);
        if ($documentCount >= 10) {
            return $this->respondDocument($request, $legalCase, false, 'error', 'document.upload.max_files_reached', null);
        }

        $form = $this->createForm(DocumentUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $firstError = null;
            foreach ($form->getErrors(true) as $error) {
                $firstError = $error;
                break;
            }
            $toastKey = $firstError?->getMessage() ?? 'document.upload.error';

            return $this->respondDocument($request, $legalCase, false, 'error', $toastKey, null);
        }

        $data = $form->getData();
        $documentType = DocumentType::from($data['documentType']);
        $this->documentUploadService->upload($legalCase, $data['file'], $documentType, $this->getUser());

        return $this->respondDocument($request, $legalCase, true, 'success', 'document.upload.success', 'hs-modal-upload-document');
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

        $this->documentUploadService->delete($document);

        return $this->respondDocument($request, $legalCase, true, 'success', 'document.delete.success', null);
    }

    /**
     * Turbo Stream (in-place, tab preserved) for Turbo clients, redirect + flash
     * otherwise. `$closeModalId` dismisses the originating modal on success.
     */
    private function respondDocument(
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
                $this->renderView('case/overview/_documents_actions_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($toastVariant, $toastKey);

        return $this->redirectToRoute('case_overview', ['id' => $case->getId(), 'tab' => 'documente']);
    }
}
