<?php

namespace App\Entity;

use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionStatus;
use App\Repository\DocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Index(columns: ['extraction_status'], name: 'idx_document_extraction_status')]
// Upload-time duplicate lookup is always scoped to one uploader, so the pair
// is the useful index, not the hash alone. Today the wizard dedupes within one
// draft in memory; this backs a persisted cross-draft lookup without a schema
// change when that is added.
#[ORM\Index(columns: ['uploaded_by_id', 'content_hash'], name: 'idx_document_uploader_content_hash')]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true)]
    private ?LegalCase $legalCase = null;

    #[ORM\Column(length: 30, enumType: DocumentType::class)]
    private DocumentType $documentType;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column(length: 255)]
    private string $storedFilename;

    #[ORM\Column]
    private int $fileSize;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $uploadedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $extractedData = null;

    #[ORM\Column(length: 20, enumType: ExtractionStatus::class)]
    private ExtractionStatus $extractionStatus = ExtractionStatus::PENDING;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2, nullable: true)]
    private ?string $extractionConfidence = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $extractionStrategy = null;

    // sha256 of the stored file, used to recognise a re-upload of the same
    // document and avoid paying for a second extraction. Nullable: rows created
    // before hashing existed have none.
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contentHash = null;

    // What the extractor believes this document is, kept apart from
    // $documentType (what the user picked) so both stay auditable.
    #[ORM\Column(length: 30, nullable: true, enumType: DocumentType::class)]
    private ?DocumentType $detectedType = null;

    // Same decimal shape as extractionConfidence, so the two read alike.
    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2, nullable: true)]
    private ?string $detectedTypeConfidence = null;

    #[ORM\Column(length: 30, nullable: true, enumType: ExtractionFailureReason::class)]
    private ?ExtractionFailureReason $extractionFailureReason = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLegalCase(): ?LegalCase
    {
        return $this->legalCase;
    }

    public function setLegalCase(?LegalCase $legalCase): static
    {
        $this->legalCase = $legalCase;

        return $this;
    }

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(DocumentType $documentType): static
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): static
    {
        $this->storedFilename = $storedFilename;

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getUploadedBy(): User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExtractedData(): ?array
    {
        return $this->extractedData;
    }

    public function setExtractedData(?array $extractedData): static
    {
        $this->extractedData = $extractedData;

        return $this;
    }

    public function getExtractionStatus(): ExtractionStatus
    {
        return $this->extractionStatus;
    }

    public function setExtractionStatus(ExtractionStatus $extractionStatus): static
    {
        $this->extractionStatus = $extractionStatus;

        return $this;
    }

    public function getExtractionConfidence(): ?string
    {
        return $this->extractionConfidence;
    }

    public function setExtractionConfidence(?string $extractionConfidence): static
    {
        $this->extractionConfidence = $extractionConfidence;

        return $this;
    }

    public function getExtractionStrategy(): ?string
    {
        return $this->extractionStrategy;
    }

    public function setExtractionStrategy(?string $extractionStrategy): static
    {
        $this->extractionStrategy = $extractionStrategy;

        return $this;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): static
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getDetectedType(): ?DocumentType
    {
        return $this->detectedType;
    }

    public function setDetectedType(?DocumentType $detectedType): static
    {
        $this->detectedType = $detectedType;

        return $this;
    }

    public function getDetectedTypeConfidence(): ?string
    {
        return $this->detectedTypeConfidence;
    }

    public function setDetectedTypeConfidence(?string $detectedTypeConfidence): static
    {
        $this->detectedTypeConfidence = $detectedTypeConfidence;

        return $this;
    }

    public function getExtractionFailureReason(): ?ExtractionFailureReason
    {
        return $this->extractionFailureReason;
    }

    public function setExtractionFailureReason(?ExtractionFailureReason $extractionFailureReason): static
    {
        $this->extractionFailureReason = $extractionFailureReason;

        return $this;
    }
}
