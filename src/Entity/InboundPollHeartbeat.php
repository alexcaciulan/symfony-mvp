<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InboundPollHeartbeatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The last run of the inbound poll, kept apart from AppSetting so no admin form can
 * edit it. A court message missed because the poll stopped would go unseen, so the
 * heartbeat check alerts on a stale success.
 */
#[ORM\Entity(repositoryClass: InboundPollHeartbeatRepository::class)]
class InboundPollHeartbeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private int $lastFetchedCount = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastFetchedCount(): int
    {
        return $this->lastFetchedCount;
    }

    public function attempted(\DateTimeImmutable $at): static
    {
        $this->lastAttemptAt = $at;

        return $this;
    }

    public function succeeded(\DateTimeImmutable $at, int $fetched): static
    {
        $this->lastSuccessAt = $at;
        $this->lastFetchedCount = $fetched;
        $this->lastError = null;

        return $this;
    }

    public function failed(string $error): static
    {
        $this->lastError = mb_substr($error, 0, 2000);

        return $this;
    }
}
