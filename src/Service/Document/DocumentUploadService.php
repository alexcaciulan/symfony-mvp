<?php

namespace App\Service\Document;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\DocumentType;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

class DocumentUploadService
{
    /**
     * Whitelist of MIME types accepted for upload, matching what the
     * extraction cascade can actually process (PdfParser handles PDF,
     * Tesseract OCR handles JPEG/PNG, Claude vision handles JPEG/PNG/GIF/WebP/PDF).
     * Anything outside this list is rejected at the boundary so untrusted
     * binary never reaches the strategies in the first place.
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private AuditLogService $auditLogService,
        private string $uploadsDir,
    ) {}

    /**
     * Upload a file and persist a Document row.
     *
     * Pas 3.0: $case is nullable — wizard step 0 uploads documents BEFORE the
     * LegalCase exists. In that case files land in cases/_pending/{userId}/{uuid}/
     * and Document.legal_case_id stays NULL until submit Step 4 attaches them
     * to the freshly created case (Pas 3.2 will also move the files to the
     * final cases/{caseId}/ directory).
     */
    public function upload(?LegalCase $case, UploadedFile $file, DocumentType $type, UserInterface $user): Document
    {
        $fileSize = $file->getSize();
        $clientOriginalName = $file->getClientOriginalName();
        $extension = $file->guessExtension() ?? 'bin';

        $storedBasename = Uuid::v4() . '.' . $extension;
        if ($case !== null) {
            $relativeDir = 'cases/' . $case->getId();
        } else {
            // Pas 3.0 wizard step 0: case doesn't exist yet. Group pending uploads
            // by user + a per-upload uuid so concurrent wizards don't collide and
            // cleanup is straightforward (rm -rf cases/_pending/{userId}/{uuid}/).
            $userId = method_exists($user, 'getId') ? $user->getId() : $user->getUserIdentifier();
            $relativeDir = 'cases/_pending/' . $userId . '/' . Uuid::v4();
        }
        $absoluteDir = $this->uploadsDir . '/' . $relativeDir;

        $file->move($absoluteDir, $storedBasename);
        $absolutePath = $absoluteDir . '/' . $storedBasename;

        // Sniff MIME server-side AFTER the file lands on disk. UploadedFile::getClientMimeType()
        // reflects the HTTP Content-Type header, which is attacker-controlled and lets a PHP
        // exploit be uploaded as `image/png`. finfo reads magic bytes from the actual file
        // content, which is what the extraction strategies need to trust.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $sniffedMimeType = $finfo->file($absolutePath);
        if ($sniffedMimeType === false || !in_array($sniffedMimeType, self::ALLOWED_MIME_TYPES, true)) {
            // Clean up the on-disk file before bailing — leaving it would let an attacker
            // burn disk space by repeatedly POSTing junk.
            @unlink($absolutePath);
            throw new \InvalidArgumentException(sprintf(
                'Uploaded file has unsupported MIME type "%s" (sniffed server-side). Allowed: %s',
                $sniffedMimeType === false ? 'unknown' : $sniffedMimeType,
                implode(', ', self::ALLOWED_MIME_TYPES),
            ));
        }

        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType($type);
        $document->setOriginalFilename($clientOriginalName);
        $document->setStoredFilename($relativeDir . '/' . $storedBasename);
        $document->setFileSize($fileSize);
        $document->setMimeType($sniffedMimeType);
        $document->setUploadedBy($user);
        $this->em->persist($document);

        $this->em->flush();

        $this->auditLogService->log('document_upload', 'Document', (string) $document->getId(), null, [
            'originalFilename' => $clientOriginalName,
            'documentType' => $type->value,
            'fileSize' => $fileSize,
            'mimeType' => $sniffedMimeType,
        ]);
        $this->em->flush();

        return $document;
    }

    public function delete(Document $document): void
    {
        $this->auditLogService->log('document_delete', 'Document', (string) $document->getId(), [
            'originalFilename' => $document->getOriginalFilename(),
            'documentType' => $document->getDocumentType()->value,
            'fileSize' => $document->getFileSize(),
        ]);

        $filePath = $this->uploadsDir . '/' . $document->getStoredFilename();
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $this->em->remove($document);
        $this->em->flush();
    }
}
