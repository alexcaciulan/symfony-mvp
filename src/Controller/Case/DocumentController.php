<?php

namespace App\Controller\Case;

use App\Enum\DocumentType;
use App\Form\Case\DocumentUploadType;
use App\Repository\DocumentRepository;
use App\Repository\LegalCaseRepository;
use App\Service\Document\DocumentUploadService;
use App\Service\Document\PdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/case')]
class DocumentController extends AbstractController
{
    public function __construct(
        private LegalCaseRepository $legalCaseRepository,
        private DocumentRepository $documentRepository,
        private EntityManagerInterface $em,
        private PdfGeneratorService $pdfGenerator,
        private DocumentUploadService $documentUploadService,
        private string $uploadsDir,
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

        if (!$document || $document->getLegalCase()->getId() !== $legalCase->getId()) {
            throw $this->createNotFoundException();
        }

        $filePath = $this->uploadsDir . '/' . $document->getStoredFilename();

        if (!file_exists($filePath)) {
            // Auto-regenerate CERERE_PDF if missing
            if ($document->getDocumentType() === DocumentType::CERERE_PDF) {
                $this->pdfGenerator->regenerateCasePdf($legalCase, $document);
                $this->em->flush();
                $filePath = $this->uploadsDir . '/' . $document->getStoredFilename();
            }

            if (!file_exists($filePath)) {
                $this->addFlash('error', 'document.download.file_missing');

                return $this->redirectToRoute('case_view', ['id' => $caseId]);
            }
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

        // Rate limit
        $limiter = $documentUploadLimiter->create($this->getUser()->getUserIdentifier());
        if (!$limiter->consume()->isAccepted()) {
            $this->addFlash('warning', 'rate_limit.document_upload');

            return $this->redirectToRoute('case_view', ['id' => $caseId]);
        }

        // Check max file count
        $documentCount = $this->documentRepository->count(['legalCase' => $legalCase]);
        if ($documentCount >= 10) {
            $this->addFlash('error', 'document.upload.max_files_reached');

            return $this->redirectToRoute('case_view', ['id' => $caseId]);
        }

        $form = $this->createForm(DocumentUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $documentType = DocumentType::from($data['documentType']);

            $this->documentUploadService->upload($legalCase, $data['file'], $documentType, $this->getUser());

            $this->addFlash('success', 'document.upload.success');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('case_view', ['id' => $caseId]);
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

        if (!$document || $document->getLegalCase()->getId() !== $legalCase->getId()) {
            throw $this->createNotFoundException();
        }

        // Prevent deletion of auto-generated CERERE_PDF
        if ($document->getDocumentType() === DocumentType::CERERE_PDF) {
            $this->addFlash('error', 'document.delete.cerere_pdf_protected');

            return $this->redirectToRoute('case_view', ['id' => $caseId]);
        }

        // CSRF check
        if (!$this->isCsrfTokenValid('delete-document-' . $documentId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'document.delete.invalid_csrf');

            return $this->redirectToRoute('case_view', ['id' => $caseId]);
        }

        $this->documentUploadService->delete($document);

        $this->addFlash('success', 'document.delete.success');

        return $this->redirectToRoute('case_view', ['id' => $caseId]);
    }
}
