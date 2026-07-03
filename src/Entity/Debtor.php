<?php

namespace App\Entity;

use App\Entity\Concern\PartyContactInfoTrait;
use App\Enum\AnafStatus;
use App\Repository\DebtorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DebtorRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Debtor
{
    use PartyContactInfoTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class, inversedBy: 'debtors')]
    #[ORM\JoinColumn(nullable: false)]
    private LegalCase $legalCase;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $addressCounty = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $addressLocality = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $administrator = null;

    #[ORM\Column(length: 20, nullable: true, enumType: AnafStatus::class)]
    private ?AnafStatus $anafStatus = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $anafCheckedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $inInsolvency = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $insolvencyCheckedAt = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $bpiProofDocument = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bpiVerifiedNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getAddressCounty(): ?string
    {
        return $this->addressCounty;
    }

    public function setAddressCounty(?string $addressCounty): static
    {
        $this->addressCounty = $addressCounty;

        return $this;
    }

    public function getAddressLocality(): ?string
    {
        return $this->addressLocality;
    }

    public function setAddressLocality(?string $addressLocality): static
    {
        $this->addressLocality = $addressLocality;

        return $this;
    }

    public function getAdministrator(): ?string
    {
        return $this->administrator;
    }

    public function setAdministrator(?string $administrator): static
    {
        $this->administrator = $administrator;

        return $this;
    }

    public function getAnafStatus(): ?AnafStatus
    {
        return $this->anafStatus;
    }

    public function setAnafStatus(?AnafStatus $anafStatus): static
    {
        $this->anafStatus = $anafStatus;

        return $this;
    }

    public function getAnafCheckedAt(): ?\DateTimeImmutable
    {
        return $this->anafCheckedAt;
    }

    public function setAnafCheckedAt(?\DateTimeImmutable $anafCheckedAt): static
    {
        $this->anafCheckedAt = $anafCheckedAt;

        return $this;
    }

    public function isInInsolvency(): bool
    {
        return $this->inInsolvency;
    }

    public function setInInsolvency(bool $inInsolvency): static
    {
        $this->inInsolvency = $inInsolvency;

        return $this;
    }

    public function getInsolvencyCheckedAt(): ?\DateTimeImmutable
    {
        return $this->insolvencyCheckedAt;
    }

    public function setInsolvencyCheckedAt(?\DateTimeImmutable $insolvencyCheckedAt): static
    {
        $this->insolvencyCheckedAt = $insolvencyCheckedAt;

        return $this;
    }

    public function getBpiProofDocument(): ?Document
    {
        return $this->bpiProofDocument;
    }

    public function setBpiProofDocument(?Document $bpiProofDocument): static
    {
        $this->bpiProofDocument = $bpiProofDocument;

        return $this;
    }

    public function getBpiVerifiedNote(): ?string
    {
        return $this->bpiVerifiedNote;
    }

    public function setBpiVerifiedNote(?string $bpiVerifiedNote): static
    {
        $this->bpiVerifiedNote = $bpiVerifiedNote;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function __toString(): string
    {
        return $this->getName();
    }
}
