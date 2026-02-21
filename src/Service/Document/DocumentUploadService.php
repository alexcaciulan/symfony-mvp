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
    public function __construct(
        private EntityManagerInterface $em,
        private AuditLogService $auditLogService,
        private string $uploadsDir,
    ) {}

    public function upload(LegalCase $case, UploadedFile $file, DocumentType $type, UserInterface $user): Document
    {
        $fileSize = $file->getSize();
        $clientOriginalName = $file->getClientOriginalName();
        $clientMimeType = $file->getClientMimeType();
        $extension = $file->guessExtension() ?? 'bin';

        $storedBasename = Uuid::v4() . '.' . $extension;
        $relativeDir = 'cases/' . $case->getId();
        $absoluteDir = $this->uploadsDir . '/' . $relativeDir;

        $file->move($absoluteDir, $storedBasename);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType($type);
        $document->setOriginalFilename($clientOriginalName);
        $document->setStoredFilename($relativeDir . '/' . $storedBasename);
        $document->setFileSize($fileSize);
        $document->setMimeType($clientMimeType);
        $document->setUploadedBy($user);
        $this->em->persist($document);

        $this->em->flush();

        $this->auditLogService->log('document_upload', 'Document', (string) $document->getId(), null, [
            'originalFilename' => $clientOriginalName,
            'documentType' => $type->value,
            'fileSize' => $fileSize,
            'mimeType' => $clientMimeType,
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
