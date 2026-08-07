<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Environment;

abstract class AbstractPdfGenerator
{
    public function __construct(
        protected Environment $twig,
        protected EntityManagerInterface $em,
        protected Security $security,
        protected string $uploadsDir,
    ) {}

    abstract protected function templatePath(): string;

    abstract protected function documentType(): DocumentType;

    abstract protected function originalFilenameFor(LegalCase $case): string;

    abstract protected function storedFilenameStem(LegalCase $case): string;

    /**
     * @return array<string, mixed>
     */
    protected function templateContext(LegalCase $case): array
    {
        return [
            'case' => $case,
            // The lawyer of the case, not whoever is logged in. These documents carry
            // a name, a bar number and now an address for service; taking them from
            // the session makes them right only for as long as the two coincide, and
            // silently wrong for anything generated outside a request by the owner.
            'user' => $case->getUser(),
            'today' => new \DateTimeImmutable(),
        ];
    }

    public function renderHtml(LegalCase $case): string
    {
        return $this->twig->render($this->templatePath(), $this->templateContext($case));
    }

    public function generate(LegalCase $case): Document
    {
        $pdfContent = $this->renderPdf($case);

        $storedFilename = $this->storedFilenameStem($case) . '.pdf';
        $this->saveToDisk($case->getId(), $storedFilename, $pdfContent);

        $uploader = $this->security->getUser();
        if (!$uploader instanceof User) {
            $uploader = $case->getUser();
        }

        // The stem is stable per case, so a second generation overwrites the file on
        // disk. Reusing the existing row rather than adding another keeps one document
        // per generated type: two rows would put the same file in the package twice
        // and leave the index listing a stale twin of itself.
        $existing = null;
        foreach ($case->getDocuments() as $candidate) {
            if ($candidate->getDocumentType() === $this->documentType()) {
                $existing = $candidate;
                break;
            }
        }

        if ($existing !== null) {
            $existing->setFileSize(strlen($pdfContent));

            return $existing;
        }

        $document = new Document();
        $document->setDocumentType($this->documentType());
        $document->setOriginalFilename($this->originalFilenameFor($case));
        $document->setStoredFilename('cases/' . $case->getId() . '/' . $storedFilename);
        $document->setFileSize(strlen($pdfContent));
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($uploader);
        // Sync both sides so the in-memory case collection reflects the new
        // document immediately (the overview re-renders from it post-generation).
        $case->addDocument($document);

        $this->em->persist($document);

        return $document;
    }

    private function renderPdf(LegalCase $case): string
    {
        $html = $this->renderHtml($case);

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
