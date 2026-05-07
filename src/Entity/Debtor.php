<?php

namespace App\Entity;

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

    #[ORM\Column(length: 10)]
    private string $personType;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(length: 13, nullable: true)]
    private ?string $personalId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tradeRegistryNumber = null;

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

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $onrcStatus = null;

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

    public function getPersonType(): string
    {
        return $this->personType;
    }

    public function setPersonType(string $personType): static
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

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): static
    {
        $this->taxId = $taxId;

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

    public function getTradeRegistryNumber(): ?string
    {
        return $this->tradeRegistryNumber;
    }

    public function setTradeRegistryNumber(?string $tradeRegistryNumber): static
    {
        $this->tradeRegistryNumber = $tradeRegistryNumber;

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

    public function getOnrcStatus(): ?string
    {
        return $this->onrcStatus;
    }

    public function setOnrcStatus(?string $onrcStatus): static
    {
        $this->onrcStatus = $onrcStatus;

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
