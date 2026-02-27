<?php

namespace App\Entity;

use App\Enum\PortalEventType;
use App\Repository\CourtPortalEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CourtPortalEventRepository::class)]
#[ORM\Index(columns: ['legal_case_id', 'event_type', 'event_date'], name: 'idx_portal_event_dedup')]
class CourtPortalEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class, inversedBy: 'portalEvents')]
    #[ORM\JoinColumn(nullable: false)]
    private LegalCase $legalCase;

    #[ORM\Column(length: 30, enumType: PortalEventType::class)]
    private PortalEventType $eventType;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $eventDate = null;

    #[ORM\Column(length: 1000)]
    private string $description;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $solutie = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $solutieSumar = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column]
    private \DateTimeImmutable $detectedAt;

    #[ORM\Column]
    private bool $notified = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->detectedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLegalCase(): LegalCase
    {
        return $this->legalCase;
    }

    public function setLegalCase(LegalCase $legalCase): static
    {
        $this->legalCase = $legalCase;

        return $this;
    }

    public function getEventType(): PortalEventType
    {
        return $this->eventType;
    }

    public function setEventType(PortalEventType $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function getEventDate(): ?\DateTimeInterface
    {
        return $this->eventDate;
    }

    public function setEventDate(?\DateTimeInterface $eventDate): static
    {
        $this->eventDate = $eventDate;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSolutie(): ?string
    {
        return $this->solutie;
    }

    public function setSolutie(?string $solutie): static
    {
        $this->solutie = $solutie;

        return $this;
    }

    public function getSolutieSumar(): ?string
    {
        return $this->solutieSumar;
    }

    public function setSolutieSumar(?string $solutieSumar): static
    {
        $this->solutieSumar = $solutieSumar;

        return $this;
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    public function setRawData(?array $rawData): static
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function isNotified(): bool
    {
        return $this->notified;
    }

    public function setNotified(bool $notified): static
    {
        $this->notified = $notified;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRawDataJson(): ?string
    {
        if ($this->rawData === null) {
            return null;
        }

        return json_encode($this->rawData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
    }

    public function __toString(): string
    {
        return sprintf('%s - %s', $this->eventType->label(), $this->description);
    }
}
