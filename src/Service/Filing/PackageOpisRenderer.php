<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\OpisPlacement;
use App\Service\Document\OpisGeneratorService;
use App\Service\Pdf\HtmlToPdfRenderer;

/**
 * The index as filed with the package: the physical files, and each annex with
 * its pages in the continuous annex numbering. Rendered after the volumes are
 * planned, because the ranges depend on the final pages. Volumes and emails are
 * transport, not content, so the index does not mention them. Never touches the
 * case's own OPIS document or the index inside the ZIP.
 */
final class PackageOpisRenderer
{
    public function __construct(
        private readonly HtmlToPdfRenderer $renderer,
        private readonly OpisGeneratorService $opisGenerator,
    ) {}

    /**
     * @param list<array{document: Document, from: int, to: int}> $annexRows
     *
     * @return int pages rendered
     */
    public function render(LegalCase $case, OpisPlacement $placement, Document $powerOfAttorney, array $annexRows, int $annexPageCount, string $outputPath): int
    {
        return $this->renderer->render('pdf/document_index_package.html.twig', [
            'case' => $case,
            'user' => $case->getUser(),
            'today' => new \DateTimeImmutable(),
            'opis_separate' => $placement === OpisPlacement::SEPARATE,
            'power_of_attorney' => $powerOfAttorney,
            'annex_rows' => $annexRows,
            'annex_page_count' => $annexPageCount,
            'claim_items_by_document' => $this->opisGenerator->claimItemsByDocument($case),
        ], $outputPath);
    }
}
