<?php

namespace App\Entity;

use App\Repository\PlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $priceMonthly;

    #[ORM\Column]
    private int $includedCases;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $pricePerExtra;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $isTrial = false;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPriceMonthly(): string
    {
        return $this->priceMonthly;
    }

    public function setPriceMonthly(string $priceMonthly): static
    {
        $this->priceMonthly = $priceMonthly;

        return $this;
    }

    public function getIncludedCases(): int
    {
        return $this->includedCases;
    }

    public function setIncludedCases(int $includedCases): static
    {
        $this->includedCases = $includedCases;

        return $this;
    }

    public function getPricePerExtra(): string
    {
        return $this->pricePerExtra;
    }

    public function setPricePerExtra(string $pricePerExtra): static
    {
        $this->pricePerExtra = $pricePerExtra;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isTrial(): bool
    {
        return $this->isTrial;
    }

    public function setIsTrial(bool $isTrial): static
    {
        $this->isTrial = $isTrial;

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
