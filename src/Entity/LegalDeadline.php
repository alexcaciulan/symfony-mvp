<?php

namespace App\Entity;

use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LegalDeadlineRepository::class)]
#[ORM\HasLifecycleCallbacks]
class LegalDeadline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class, inversedBy: 'deadlines')]
    #[ORM\JoinColumn(nullable: false)]
    private LegalCase $legalCase;

    #[ORM\Column(length: 30, enumType: DeadlineType::class)]
    private DeadlineType $type;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $deadlineDate;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: DeadlinePriority::class)]
    private DeadlinePriority $priority = DeadlinePriority::MEDIUM;

    #[ORM\Column]
    private bool $completed = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private bool $alertSent7 = false;

    #[ORM\Column]
    private bool $alertSent3 = false;

    #[ORM\Column]
    private bool $alertSent1 = false;

    #[ORM\Column]
    private bool $alertSentExpired = false;

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

    public function getType(): DeadlineType
    {
        return $this->type;
    }

    public function setType(DeadlineType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDeadlineDate(): \DateTimeInterface
    {
        return $this->deadlineDate;
    }

    public function setDeadlineDate(\DateTimeInterface $deadlineDate): static
    {
        $this->deadlineDate = $deadlineDate;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPriority(): DeadlinePriority
    {
        return $this->priority;
    }

    public function setPriority(DeadlinePriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function setCompleted(bool $completed): static
    {
        $this->completed = $completed;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function markCompleted(): static
    {
        $this->completed = true;
        $this->completedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isAlertSent7(): bool
    {
        return $this->alertSent7;
    }

    public function setAlertSent7(bool $alertSent7): static
    {
        $this->alertSent7 = $alertSent7;

        return $this;
    }

    public function isAlertSent3(): bool
    {
        return $this->alertSent3;
    }

    public function setAlertSent3(bool $alertSent3): static
    {
        $this->alertSent3 = $alertSent3;

        return $this;
    }

    public function isAlertSent1(): bool
    {
        return $this->alertSent1;
    }

    public function setAlertSent1(bool $alertSent1): static
    {
        $this->alertSent1 = $alertSent1;

        return $this;
    }

    public function isAlertSentExpired(): bool
    {
        return $this->alertSentExpired;
    }

    public function setAlertSentExpired(bool $alertSentExpired): static
    {
        $this->alertSentExpired = $alertSentExpired;

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
}
