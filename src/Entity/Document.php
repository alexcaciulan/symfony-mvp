<?php

namespace App\Entity;

use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Repository\DocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Index(columns: ['extraction_status'], name: 'idx_document_extraction_status')]
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
}
