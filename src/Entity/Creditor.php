<?php

namespace App\Entity;

use App\Enum\PersonType;
use App\Repository\CreditorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreditorRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_creditor_user_cui', columns: ['user_id', 'cui'])]
class Creditor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

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

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $bankName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalRepresentative = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, LegalCase> */
    #[ORM\OneToMany(targetEntity: LegalCase::class, mappedBy: 'creditor')]
    private Collection $legalCases;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->legalCases = new ArrayCollection();
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

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

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): static
    {
        $this->bankName = $bankName;

        return $this;
    }

    public function getLegalRepresentative(): ?string
    {
        return $this->legalRepresentative;
    }

    public function setLegalRepresentative(?string $legalRepresentative): static
    {
        $this->legalRepresentative = $legalRepresentative;

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

    /** @return Collection<int, LegalCase> */
    public function getLegalCases(): Collection
    {
        return $this->legalCases;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
