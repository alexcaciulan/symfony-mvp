<?php

namespace App\Entity;

use App\Enum\SubscriptionStatus;
use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Plan $plan;

    #[ORM\Column(length: 20, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column]
    private int $casesConsumed = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    /**
     * Netopia recurring-payment token (`token_id`), saved on the first successful
     * on-session payment so monthly renewals can be charged off-session without
     * user interaction. Never the PAN: only this opaque token is stored.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recurringToken = null;

    /** Token validity horizon (usually the card expiry); past this, re-authorization is required. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recurringTokenExpiresAt = null;

    /** Masked card for display only (e.g. "4111 **** **** 1111"). Not sensitive. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cardMask = null;

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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function setStatus(SubscriptionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCurrentPeriodStart(): \DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function setCurrentPeriodStart(\DateTimeImmutable $currentPeriodStart): static
    {
        $this->currentPeriodStart = $currentPeriodStart;

        return $this;
    }

    public function getCurrentPeriodEnd(): \DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(\DateTimeImmutable $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;

        return $this;
    }

    public function getCasesConsumed(): int
    {
        return $this->casesConsumed;
    }

    public function setCasesConsumed(int $casesConsumed): static
    {
        $this->casesConsumed = $casesConsumed;

        return $this;
    }

    public function incrementCasesConsumed(): static
    {
        ++$this->casesConsumed;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getRecurringToken(): ?string
    {
        return $this->recurringToken;
    }

    public function setRecurringToken(?string $recurringToken): static
    {
        $this->recurringToken = $recurringToken;

        return $this;
    }

    public function getRecurringTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->recurringTokenExpiresAt;
    }

    public function setRecurringTokenExpiresAt(?\DateTimeImmutable $recurringTokenExpiresAt): static
    {
        $this->recurringTokenExpiresAt = $recurringTokenExpiresAt;

        return $this;
    }

    public function getCardMask(): ?string
    {
        return $this->cardMask;
    }

    public function setCardMask(?string $cardMask): static
    {
        $this->cardMask = $cardMask;

        return $this;
    }

    /**
     * Whether a usable recurring token is stored and not past its expiry. Drives
     * whether a renewal can be charged off-session or must fall back to on-session
     * re-authorization.
     */
    public function hasChargeableToken(?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->recurringToken) {
            return false;
        }
        if (null === $this->recurringTokenExpiresAt) {
            return true;
        }

        return $this->recurringTokenExpiresAt >= ($now ?? new \DateTimeImmutable());
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
