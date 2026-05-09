<?php

namespace App\Entity;

use App\Enum\AnafStatus;
use App\Enum\PersonType;
use App\Repository\DebtorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DebtorRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Debtor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class, inversedBy: 'debtors')]
    #[ORM\JoinColumn(nullable: false)]
    private LegalCase $legalCase;

    #[ORM\Column(length: 10, enumType: PersonType::class)]
    private PersonType $personType;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cui = null;

    #[ORM\Column(length: 13, nullable: true)]
    private ?string $personalId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $onrcNumber = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $address;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

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

    public function getPersonType(): PersonType
    {
        return $this->personType;
    }

    public function setPersonType(PersonType $personType): static
    {
        $this->personType = $personType;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCui(): ?string
    {
        return $this->cui;
    }

    public function setCui(?string $cui): static
    {
        $this->cui = $cui;

        return $this;
    }

    public function getPersonalId(): ?string
    {
        return $this->personalId;
    }

    public function setPersonalId(?string $personalId): static
    {
        $this->personalId = $personalId;

        return $this;
    }

    public function getOnrcNumber(): ?string
    {
        return $this->onrcNumber;
    }

    public function setOnrcNumber(?string $onrcNumber): static
    {
        $this->onrcNumber = $onrcNumber;

        return $this;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $iban;

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
        return $this->name;
    }
}
