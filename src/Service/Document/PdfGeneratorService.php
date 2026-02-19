<?php

namespace App\Service\Document;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Environment;

class PdfGeneratorService
{
    public function __construct(
        private Environment $twig,
        private EntityManagerInterface $em,
        private Security $security,
        private string $uploadsDir,
    ) {}

    public function generateCasePdf(LegalCase $case): Document
    {
        $pdfContent = $this->renderPdf($case);

        $storedFilename = 'cerere_' . $case->getId() . '.pdf';
        $this->saveToDisk($case->getId(), $storedFilename, $pdfContent);

        // Create Document entity
        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::CERERE_PDF);
        $document->setOriginalFilename('Cerere cu valoare redusă #' . $case->getId() . '.pdf');
        $document->setStoredFilename('cases/' . $case->getId() . '/' . $storedFilename);
        $document->setFileSize(strlen($pdfContent));
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($case->getUser());

        $this->em->persist($document);

        return $document;
    }

    public function regenerateCasePdf(LegalCase $case, Document $document): void
    {
        $pdfContent = $this->renderPdf($case);
        $this->saveToDisk($case->getId(), basename($document->getStoredFilename()), $pdfContent);
        $document->setFileSize(strlen($pdfContent));
    }

    private function renderPdf(LegalCase $case): string
    {
        $html = $this->twig->render('pdf/cerere_valoare_redusa.html.twig', [
            'case' => $case,
        ]);

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function saveToDisk(int $caseId, string $filename, string $content): void
    {
        $dir = $this->uploadsDir . '/cases/' . $caseId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $filename, $content);
    }
}
