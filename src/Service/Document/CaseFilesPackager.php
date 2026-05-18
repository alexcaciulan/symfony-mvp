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
        // Numbered top-level files (1, 2, 3) — predictable order for the
        // judge handling the file. Other types group into subfolders.
        $orderedTopLevel = [
            DocumentType::CERERE_OP->value => '01_cerere_ordonanta_plata',
            DocumentType::OPIS->value => '02_opis_documente',
            DocumentType::SOMATIE->value => '03_somatie_de_plata',
        ];

        foreach ($case->getDocuments() as $document) {
            $diskPath = $this->uploadsDir . '/' . $document->getStoredFilename();
            if (!file_exists($diskPath)) {
                continue;
            }

            $typeValue = $document->getDocumentType()->value;

            if (isset($orderedTopLevel[$typeValue])) {
                $extension = pathinfo($document->getOriginalFilename(), PATHINFO_EXTENSION) ?: 'pdf';
                $zip->addFile($diskPath, sprintf('%s.%s', $orderedTopLevel[$typeValue], $extension));
                continue;
            }

            // basename() defensive — `originalFilename` vine din `UploadedFile`
            // care strip-uiește deja paths, dar exprimă explicit invariantul ca
            // să rămână safe dacă sursa câmpului se schimbă.
            $safeName = basename($document->getOriginalFilename());

            if ($document->getDocumentType() === DocumentType::DOVADA_COMUNICARE) {
                $zip->addFile($diskPath, 'dovada_comunicare/' . $safeName);
                continue;
            }

            $zip->addFile($diskPath, 'anexe/' . $safeName);
        }
    }
}
