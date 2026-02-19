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

        $pdfContent = $dompdf->output();

        // Save to disk
        $dir = $this->uploadsDir . '/cases/' . $case->getId();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $storedFilename = 'cerere_' . $case->getId() . '.pdf';
        $filePath = $dir . '/' . $storedFilename;
        file_put_contents($filePath, $pdfContent);

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
}
