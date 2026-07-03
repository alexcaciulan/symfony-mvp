<?php

namespace App\Entity\Concern;

use App\Enum\PersonType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Shared identity and contact fields for a party (Creditor or Debtor).
 *
 * These are the columns common to both parties; party-specific data (creditor
 * bank details, debtor ANAF/BPI verification) stays on the owning entity. Using
 * a trait keeps the columns flat on each table (so DQL stays `c.name` and
 * criteria stay `['cui' => ...]`) and exposes the getters/setters natively
 * without delegation.
 */
trait PartyContactInfoTrait
{
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
}
