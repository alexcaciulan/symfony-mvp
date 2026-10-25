<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\Document;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Enum\FilingPartRole;
use App\Service\Pdf\GhostscriptCompressor;
use App\Service\Pdf\HtmlToPdfRenderer;
use App\Service\Pdf\ImageToPdfConverter;
use App\Service\Pdf\PdfPageNumberOverlay;
use App\Service\Pdf\PdfToolException;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;

/**
 * The annexes of a filing package: each document as a checked PDF, the continuous
 * page numbering, compression when the size budget asks for it, and the split into
 * numbered volumes, each with its cover.
 *
 * @phpstan-type NormalizedAnnex array{document: Document, path: string, pages: int, bytes: int, compressed: bool}
 * @phpstan-type AnnexRange array{document: Document, from: int, to: int}
 */
final class AnnexBundleBuilder
{
    private const PDF_MIME = 'application/pdf';
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly FilingAnnexSelector $selector,
        private readonly FilingSizePlanner $planner,
        private readonly FilingPartWriter $parts,
        private readonly ProcessSandbox $sandbox,
        private readonly QpdfRunner $qpdf,
        private readonly GhostscriptCompressor $compressor,
        private readonly ImageToPdfConverter $imageConverter,
        private readonly PdfPageNumberOverlay $numberOverlay,
        private readonly HtmlToPdfRenderer $renderer,
    ) {}

    /**
     * Each annex as a checked PDF in the sandbox, with its page count.
     *
     * @param list<Document> $annexes
     *
     * @return list<NormalizedAnnex>
     */
    public function normalize(array $annexes): array
    {
        $normalized = [];
        foreach ($annexes as $document) {
            $path = $this->toPdf($document);
            $normalized[] = [
                'document' => $document,
                'path' => $path,
                'pages' => $this->qpdf->pageCount($path),
                'bytes' => (int) filesize($path),
                'compressed' => false,
            ];
        }

        return $normalized;
    }

    /** The document as a checked PDF; an image is converted, never compressed. */
    public function toPdf(Document $document): string
    {
        $source = $this->selector->path($document);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($source);

        try {
            if ($mime === self::PDF_MIME) {
                $this->qpdf->inspect($source);

                return $source;
            }

            if (in_array($mime, self::IMAGE_MIMES, true)) {
                $out = $this->sandbox->tempPath();
                $this->imageConverter->convert($source, $out);

                return $out;
            }
        } catch (PdfToolException $e) {
            throw $e->forDocument($document->getOriginalFilename());
        }

        throw (new PdfToolException('Unsupported file type ' . $mime, 'filing.package.error.unsupported'))
            ->forDocument($document->getOriginalFilename());
    }

    /**
     * Continuous annex numbering: document k starts right after document k-1.
     *
     * @param list<NormalizedAnnex> $normalized
     *
     * @return array<int, AnnexRange>
     */
    public function ranges(array $normalized): array
    {
        $ranges = [];
        $next = 1;
        foreach ($normalized as $annex) {
            $id = (int) $annex['document']->getId();
            $ranges[$id] = ['document' => $annex['document'], 'from' => $next, 'to' => $next + $annex['pages'] - 1];
            $next += $annex['pages'];
        }

        return $ranges;
    }

    /**
     * @param array<int, AnnexRange> $ranges
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public function rangesForStorage(array $ranges): array
    {
        $stored = [];
        foreach ($ranges as $id => $range) {
            $stored[(string) $id] = [$range['from'], $range['to']];
        }

        return $stored;
    }

    /**
     * Plans; compresses the annexes when asked to, then plans again.
     *
     * @param list<NormalizedAnnex> $normalized
     *
     * @return array{0: FilingSizePlan, 1: list<NormalizedAnnex>}
     */
    public function planWithCompression(int $firstEmailBytes, array $normalized, int $budget): array
    {
        $plan = $this->planner->plan($firstEmailBytes, $this->plannerAnnexes($normalized), $budget, false);
        if ($plan->needsCompression && $plan->volumes === []) {
            $normalized = $this->compressAll($normalized);
            $plan = $this->planner->plan($firstEmailBytes, $this->plannerAnnexes($normalized), $budget, true);
        }

        $this->assertPlanOk($plan, $normalized);

        return [$plan, $normalized];
    }

    /**
     * Builds the volumes, then checks them as written: the estimate can miss (cover
     * fonts, overlay resources). One re-plan with the measured overhead, compressing
     * first if that had not been needed yet, then a clear error rather than an
     * oversized email.
     *
     * @param list<NormalizedAnnex>  $normalized
     * @param array<int, AnnexRange> $ranges
     * @param list<string>           $written
     *
     * @return array{0: FilingSizePlan, 1: list<FilingPackagePart>}
     */
    public function buildVolumesWithinBudget(LegalCase $case, FilingSizePlan $plan, array $normalized, array $ranges, string $relativeDir, int $position, array &$written, int $firstEmailBytes, int $budget): array
    {
        $volumeParts = $this->buildVolumes($case, $plan, $normalized, $ranges, $relativeDir, $position, $written);
        if (!$this->exceedsBudget($plan, $firstEmailBytes, $volumeParts, $budget)) {
            return [$plan, $volumeParts];
        }

        $this->parts->removeFiles(array_map(fn (FilingPackagePart $p): string => $this->parts->absolute($p->getUnsignedPath()), $volumeParts));
        if (!$plan->needsCompression) {
            $normalized = $this->compressAll($normalized);
        }
        $plan = $this->planner->plan($firstEmailBytes, $this->plannerAnnexes($normalized), $budget, true, ...$this->measuredOverhead($volumeParts, $normalized));
        $this->assertPlanOk($plan, $normalized);
        $volumeParts = $this->buildVolumes($case, $plan, $normalized, $ranges, $relativeDir, $position, $written);
        if ($this->exceedsBudget($plan, $firstEmailBytes, $volumeParts, $budget)) {
            throw new FilingPackageBlockedException('filing.package.error.too_large');
        }

        return [$plan, $volumeParts];
    }

    /**
     * Compresses every annex that is not compressed yet; keeps the original where
     * Ghostscript would not help or would change the text.
     *
     * @param list<NormalizedAnnex> $normalized
     *
     * @return list<NormalizedAnnex>
     */
    private function compressAll(array $normalized): array
    {
        foreach ($normalized as $i => $annex) {
            if ($annex['compressed']) {
                continue;
            }
            $result = $this->compressor->compress($annex['path']);
            if ($result->applied) {
                $normalized[$i]['path'] = $result->path;
                $normalized[$i]['bytes'] = $result->bytesAfter;
                $normalized[$i]['compressed'] = true;
            }
        }

        return $normalized;
    }

    /**
     * @param list<NormalizedAnnex> $normalized
     */
    private function assertPlanOk(FilingSizePlan $plan, array $normalized): void
    {
        if ($plan->error === FilingSizePlanner::ERROR_FIRST_EMAIL_TOO_LARGE) {
            throw new FilingPackageBlockedException('filing.package.error.first_email_too_large');
        }
        if ($plan->error === FilingSizePlanner::ERROR_ANNEX_TOO_LARGE) {
            $name = '';
            foreach ($normalized as $annex) {
                if ($annex['document']->getId() === $plan->errorDocumentId) {
                    $name = $annex['document']->getOriginalFilename();
                }
            }

            throw new FilingPackageBlockedException('filing.package.error.annex_too_large', ['%document%' => $name]);
        }
    }

    /**
     * @param list<NormalizedAnnex> $normalized
     *
     * @return list<array{id: int, bytes: int, pages: int}>
     */
    private function plannerAnnexes(array $normalized): array
    {
        return array_map(static fn (array $a): array => [
            'id' => (int) $a['document']->getId(),
            'bytes' => $a['bytes'],
            'pages' => $a['pages'],
        ], $normalized);
    }

    /**
     * One PDF per volume: numbered pages, the cover in front (outside the
     * numbering), compacted losslessly, tagged with its part token.
     *
     * @param list<NormalizedAnnex>  $normalized
     * @param array<int, AnnexRange> $ranges
     * @param list<string>           $written
     *
     * @return list<FilingPackagePart>
     */
    private function buildVolumes(LegalCase $case, FilingSizePlan $plan, array $normalized, array $ranges, string $relativeDir, int $position, array &$written): array
    {
        $byId = [];
        foreach ($normalized as $annex) {
            $byId[(int) $annex['document']->getId()] = $annex;
        }

        $parts = [];
        $volumeCount = count($plan->volumes);
        foreach ($plan->volumes as $index => $documentIds) {
            $volume = $index + 1;
            $from = $ranges[$documentIds[0]]['from'];
            $to = $ranges[$documentIds[array_key_last($documentIds)]]['to'];

            $merged = $this->sandbox->tempPath();
            $this->qpdf->merge(array_map(static fn (int $id): string => $byId[$id]['path'], $documentIds), $merged);
            $numbered = $this->sandbox->tempPath();
            $this->numberOverlay->apply($merged, $numbered, $from);

            $cover = $this->sandbox->tempPath();
            $this->renderer->render('pdf/annex_cover.html.twig', [
                'case' => $case,
                'user' => $case->getUser(),
                'page_from' => $from,
                'page_to' => $to,
            ], $cover);

            $withCover = $this->sandbox->tempPath();
            $this->qpdf->merge([$cover, $numbered], $withCover);

            $compressed = false;
            foreach ($documentIds as $id) {
                $compressed = $compressed || $byId[$id]['compressed'];
            }

            $part = $this->parts->generatedPart(FilingPartRole::ANNEX_BUNDLE, $position + $index, $withCover, $relativeDir, $volume, $written, $documentIds, $this->parts->title(FilingPartRole::ANNEX_BUNDLE, $volume, $volumeCount));
            $part->setVolume($volume, $volumeCount);
            $part->setAnnexPageRange($from, $to);
            $part->setCompressionApplied($compressed);
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * Whether any email, with the files as actually written, is over the budget.
     *
     * @param list<FilingPackagePart> $volumeParts
     */
    private function exceedsBudget(FilingSizePlan $plan, int $firstEmailBytes, array $volumeParts, int $budget): bool
    {
        $emails = array_fill(0, max(1, $plan->emailCount()), 0);
        $emails[0] = $firstEmailBytes;
        foreach ($volumeParts as $part) {
            $emails[$plan->emailForVolume((int) $part->getVolume()) - 1] += $part->getUnsignedBytes();
        }

        return max($emails) > $budget;
    }

    /**
     * @param list<FilingPackagePart> $volumeParts
     * @param list<NormalizedAnnex>   $normalized
     *
     * @return array{0: int, 1: int}
     */
    private function measuredOverhead(array $volumeParts, array $normalized): array
    {
        $sourceBytes = array_sum(array_column($normalized, 'bytes'));
        $pages = max(1, array_sum(array_column($normalized, 'pages')));
        $volumes = max(1, count($volumeParts));
        $actual = array_sum(array_map(static fn (FilingPackagePart $p): int => $p->getUnsignedBytes(), $volumeParts));
        $extra = max(0, $actual - $sourceBytes);

        // Attribute the measured excess to volumes and pages in the default proportion, with a margin.
        $volumeOverhead = (int) ceil(max(FilingSizePlanner::DEFAULT_VOLUME_OVERHEAD, $extra / $volumes) * 1.1);
        $pageOverhead = (int) ceil(max(FilingSizePlanner::DEFAULT_PAGE_OVERHEAD, $extra / $pages) * 1.1);

        return [$volumeOverhead, $pageOverhead];
    }
}
