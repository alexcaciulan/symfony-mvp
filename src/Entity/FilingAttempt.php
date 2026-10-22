<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingAttemptSource;
use App\Repository\FilingAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

/** One try to hand a part to the SMTP server, or a later finding about it. Never carries debtor data. */
#[ORM\Entity(repositoryClass: FilingAttemptRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_filing_attempt_part_no', columns: ['part_id', 'attempt_no'])]
class FilingAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FilingEmailPart::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FilingEmailPart $part;

    #[ORM\Column]
    private int $attemptNo;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 20, nullable: true, enumType: FilingAttemptOutcome::class)]
    private ?FilingAttemptOutcome $outcome = null;

    #[ORM\Column(nullable: true)]
    private ?int $smtpCode = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $enhancedStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $errorClass = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 30, enumType: FilingAttemptSource::class)]
    private FilingAttemptSource $source;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextRetryAt = null;

    public function __construct(FilingEmailPart $part, int $attemptNo, FilingAttemptSource $source, \DateTimeImmutable $startedAt)
    {
        $this->part = $part;
        $this->attemptNo = $attemptNo;
        $this->source = $source;
        $this->startedAt = $startedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPart(): FilingEmailPart
    {
        return $this->part;
    }

    public function getAttemptNo(): int
    {
        return $this->attemptNo;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getOutcome(): ?FilingAttemptOutcome
    {
        return $this->outcome;
    }

    public function getSmtpCode(): ?int
    {
        return $this->smtpCode;
    }

    public function getEnhancedStatus(): ?string
    {
        return $this->enhancedStatus;
    }

    public function getErrorClass(): ?string
    {
        return $this->errorClass;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getSource(): FilingAttemptSource
    {
        return $this->source;
    }

    public function getNextRetryAt(): ?\DateTimeImmutable
    {
        return $this->nextRetryAt;
    }

    public function finish(
        FilingAttemptOutcome $outcome,
        \DateTimeImmutable $at,
        ?int $smtpCode = null,
        ?string $enhancedStatus = null,
        ?string $errorClass = null,
        ?string $message = null,
    ): static {
        $this->outcome = $outcome;
        $this->finishedAt = $at;
        $this->smtpCode = $smtpCode;
        $this->enhancedStatus = $enhancedStatus;
        $this->errorClass = $errorClass;
        $this->message = $message !== null ? mb_substr($message, 0, 500) : null;

        return $this;
    }
}
