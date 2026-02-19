<?php

namespace App\Controller\Case;

use App\Repository\DocumentRepository;
use App\Repository\LegalCaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/case')]
class DocumentController extends AbstractController
{
    public function __construct(
        private LegalCaseRepository $legalCaseRepository,
        private DocumentRepository $documentRepository,
        private string $uploadsDir,
    ) {}

    #[Route('/{caseId}/document/{documentId}/download', name: 'case_document_download', requirements: ['caseId' => '\d+', 'documentId' => '\d+'], methods: ['GET'])]
    public function download(int $caseId, int $documentId): BinaryFileResponse
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
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getOriginalFilename()
        );

        return $response;
    }
}
