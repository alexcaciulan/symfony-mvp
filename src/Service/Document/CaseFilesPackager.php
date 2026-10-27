<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\DocumentType;

/**
 * Construiește un ZIP pentru depunere fizică la registratura instanței.
 * Conține: cerere OP (CERERE_OP), opis (OPIS), somația (SOMATIE) numerotate
 * la nivelul rădăcinii ZIP + dovezile de comunicare în folder dedicat +
 * toate celelalte Documents (CONTRACT/FACTURA/ANEXA) în `anexe/`. Folosit
 * pentru re-download repetat fără regenerare PDF-uri.
 */
final class CaseFilesPackager
{
    public function __construct(
        private readonly string $uploadsDir,
    ) {}

    /**
     * @return string Absolute path la ZIP creat (persistat în `cases/{id}/packages/`).
     *
     * @throws \RuntimeException dacă ZipArchive nu poate fi deschis sau scris.
     */
    public function package(LegalCase $case): string
    {
        $caseId = $case->getId();
        if ($caseId === null) {
            throw new \InvalidArgumentException('Cannot package files for unmanaged LegalCase (no ID).');
        }

        $packageDir = sprintf('%s/cases/%d/packages', $this->uploadsDir, $caseId);
        if (!is_dir($packageDir) && !mkdir($packageDir, 0755, true) && !is_dir($packageDir)) {
            throw new \RuntimeException(sprintf('Cannot create package directory: %s', $packageDir));
        }

        $zipPath = sprintf('%s/Pachet_%s.zip', $packageDir, $case->getCaseNumber());

        // Overwrite existing — re-download includes any new uploaded sources.
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new \RuntimeException(sprintf('Cannot open ZIP for writing (code %d): %s', $result, $zipPath));
        }

        try {
            $this->addDocumentsToZip($zip, $case);
        } finally {
            $zip->close();
        }

        return $zipPath;
    }

    private function addDocumentsToZip(\ZipArchive $zip, LegalCase $case): void
    {
        // Numbered top-level files, in the order the judge handling the case will
        // read them. Other types group into subfolders. The stamp-duty proof sits at
        // the top level rather than in `anexe/` because CPC art. 197 requires it to be
        // attached to the petition itself, not buried among the exhibits.
        $orderedTopLevel = [
            DocumentType::CERERE_OP->value => '01_cerere_ordonanta_plata',
            DocumentType::OPIS->value => '02_opis_documente',
            DocumentType::SOMATIE->value => '03_somatie_de_plata',
            DocumentType::DOVADA_TAXA_TIMBRU->value => '04_dovada_taxa_timbru',
            // Proof of the lawyer's authority to act, filed with the petition. Sits
            // at the top level rather than among the exhibits because it is an act of
            // the petition itself, not evidence of the debt.
            DocumentType::IMPUTERNICIRE_AVOCATIALA->value => '05_imputernicire_avocatiala',
        ];

        // ZipArchive::addFile replaces an entry with the same name, so a second power
        // of attorney or two invoices uploaded as "factura.pdf" would silently drop a
        // file from the package. Every repeated name gets a numeric suffix instead.
        $used = [];

        foreach ($case->getDocuments() as $document) {
            $diskPath = $this->uploadsDir . '/' . $document->getStoredFilename();
            $typeValue = $document->getDocumentType()->value;

            if (!file_exists($diskPath)) {
                // A missing annex is a gap the lawyer can see and fix. These are not:
                // the petition would go out claiming an annexed proof of payment or of
                // service that is not in the package, and the absence of proof that the
                // summons was served gets the petition rejected as inadmissible. Fail
                // loudly rather than ship a package that contradicts its own contents.
                if (isset($orderedTopLevel[$typeValue]) || $document->getDocumentType() === DocumentType::DOVADA_COMUNICARE) {
                    throw new MissingDocumentFileException($document);
                }

                continue;
            }

            if (isset($orderedTopLevel[$typeValue])) {
                $extension = pathinfo($document->getOriginalFilename(), PATHINFO_EXTENSION) ?: 'pdf';
                $zip->addFile($diskPath, self::uniqueEntryName(sprintf('%s.%s', $orderedTopLevel[$typeValue], $extension), $used));
                continue;
            }

            // basename() defensive — `originalFilename` vine din `UploadedFile`
            // care strip-uiește deja paths, dar exprimă explicit invariantul ca
            // să rămână safe dacă sursa câmpului se schimbă.
            $safeName = basename($document->getOriginalFilename());

            if ($document->getDocumentType() === DocumentType::DOVADA_COMUNICARE) {
                $zip->addFile($diskPath, self::uniqueEntryName('dovada_comunicare/' . $safeName, $used));
                continue;
            }

            $zip->addFile($diskPath, self::uniqueEntryName('anexe/' . $safeName, $used));
        }
    }

    /**
     * The entry name, or the same name with "_2", "_3"... before the extension when it
     * is already in the archive. Compared case-insensitively, as most unzip tools do.
     *
     * @param array<string, true> $used
     */
    private static function uniqueEntryName(string $name, array &$used): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
        $candidate = $name;
        for ($n = 2; isset($used[mb_strtolower($candidate)]); ++$n) {
            $candidate = $extension === '' ? sprintf('%s_%d', $stem, $n) : sprintf('%s_%d.%s', $stem, $n, $extension);
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
