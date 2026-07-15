<?php

namespace App\Entity;

use App\Enum\NotificationChannel;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(name: 'idx_notification_user_read', columns: ['user_id', 'is_read'])]
#[ORM\Index(name: 'idx_notification_user_created', columns: ['user_id', 'created_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'notifications')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: LegalCase::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?LegalCase $legalCase = null;

    #[ORM\Column(length: 50)]
    private string $type;

    #[ORM\Column(length: 20, enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 1000)]
    private string $message;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $resourceLink = null;

    #[ORM\Column]
    private bool $isRead = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /**
     * Stable idempotency key for retry-prone producers (Phase C). Unique so a
     * resent webhook/IPN or a re-run worker cannot persist a duplicate row.
     */
    #[ORM\Column(length: 191, unique: true, nullable: true)]
    private ?string $dedupKey = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getLegalCase(): ?LegalCase
    {
        return $this->legalCase;
    }

    public function setLegalCase(?LegalCase $legalCase): static
    {
        $this->legalCase = $legalCase;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function setChannel(NotificationChannel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getResourceLink(): ?string
    {
        return $this->resourceLink;
    }

    public function setResourceLink(?string $resourceLink): static
    {
        $this->resourceLink = $resourceLink;

        return $this;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): static
    {
        $this->isRead = $isRead;
        // readAt is the honest cross-device "when read" source; isRead stays the
        // fast filter. Set on first read, cleared when marked unread.
        if ($isRead) {
            $this->readAt ??= new \DateTimeImmutable();
        } else {
            $this->readAt = null;
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getDedupKey(): ?string
    {
        return $this->dedupKey;
    }

    public function setDedupKey(?string $dedupKey): static
    {
        $this->dedupKey = $dedupKey;

        return $this;
    }
}
