<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FilingPartRole;
use App\Enum\FilingPartStatus;
use App\Repository\FilingEmailPartRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One email of a submission. Its identifiers are fixed when the submission is
 * created, and the exact bytes are written to disk before they are handed to the
 * SMTP server, so the record of what was sent never depends on a later rebuild.
 */
#[ORM\Entity(repositoryClass: FilingEmailPartRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_filing_email_part_sequence', columns: ['submission_id', 'sequence'])]
class FilingEmailPart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FilingSubmission::class, inversedBy: 'parts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FilingSubmission $submission;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column]
    private int $total;

    /** @var Collection<int, FilingPackagePart> */
    #[ORM\ManyToMany(targetEntity: FilingPackagePart::class)]
    #[ORM\JoinTable(name: 'filing_email_part_package_part')]
    #[ORM\JoinColumn(name: 'email_part_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'package_part_id', onDelete: 'CASCADE')]
    private Collection $packageParts;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(length: 255)]
    private string $messageId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $inReplyTo = null;

    #[ORM\Column(name: 'references_header', length: 255, nullable: true)]
    private ?string $references = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $verpToken;

    #[ORM\Column(nullable: true)]
    private ?int $encodedBytes = null;

    #[ORM\Column(length: 30, enumType: FilingPartStatus::class)]
    private FilingPartStatus $status = FilingPartStatus::PENDING;

    #[ORM\Column]
    private int $attemptCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emlPath = null;

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $emlSha256 = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastSmtpCode = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lastEnhancedStatus = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastError = null;

    public function __construct(
        FilingSubmission $submission,
        int $sequence,
        int $total,
        string $subject,
        string $messageId,
        string $verpToken,
    ) {
        $this->submission = $submission;
        $this->sequence = $sequence;
        $this->total = $total;
        $this->subject = $subject;
        $this->messageId = $messageId;
        $this->verpToken = $verpToken;
        $this->packageParts = new ArrayCollection();
        $submission->addPart($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubmission(): FilingSubmission
    {
        return $this->submission;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function isFirst(): bool
    {
        return $this->sequence === 1;
    }

    public function isLast(): bool
    {
        return $this->sequence === $this->total;
    }

    /** @return Collection<int, FilingPackagePart> */
    public function getPackageParts(): Collection
    {
        return $this->packageParts;
    }

    /**
     * The files of this email in package order.
     *
     * @return list<FilingPackagePart>
     */
    public function getOrderedPackageParts(): array
    {
        $parts = $this->packageParts->toArray();
        usort($parts, static fn (FilingPackagePart $a, FilingPackagePart $b): int => $a->getPosition() <=> $b->getPosition());

        return array_values($parts);
    }

    public function addPackagePart(FilingPackagePart $part): static
    {
        if (!$this->packageParts->contains($part)) {
            $this->packageParts->add($part);
        }

        return $this;
    }

    public function carries(FilingPartRole $role): bool
    {
        return $this->packageParts->exists(static fn (int $_k, FilingPackagePart $p): bool => $p->getRole() === $role);
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function getReferences(): ?string
    {
        return $this->references;
    }

    public function setThreading(?string $inReplyTo, ?string $references): static
    {
        $this->inReplyTo = $inReplyTo;
        $this->references = $references;

        return $this;
    }

    public function getVerpToken(): string
    {
        return $this->verpToken;
    }

    public function getEncodedBytes(): ?int
    {
        return $this->encodedBytes;
    }

    public function getStatus(): FilingPartStatus
    {
        return $this->status;
    }

    public function setStatus(FilingPartStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function startAttempt(): int
    {
        $this->status = FilingPartStatus::SENDING;

        return ++$this->attemptCount;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markAccepted(\DateTimeImmutable $at): static
    {
        $this->status = FilingPartStatus::ACCEPTED;
        $this->sentAt = $at;
        $this->lastError = null;

        return $this;
    }

    public function getEmlPath(): ?string
    {
        return $this->emlPath;
    }

    public function getEmlSha256(): ?string
    {
        return $this->emlSha256;
    }

    public function recordEml(string $path, string $sha256, int $encodedBytes): static
    {
        $this->emlPath = $path;
        $this->emlSha256 = $sha256;
        $this->encodedBytes = $encodedBytes;

        return $this;
    }

    public function getLastSmtpCode(): ?int
    {
        return $this->lastSmtpCode;
    }

    public function getLastEnhancedStatus(): ?string
    {
        return $this->lastEnhancedStatus;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function recordFailure(FilingPartStatus $status, ?int $smtpCode, ?string $enhancedStatus, string $error): static
    {
        $this->status = $status;
        $this->lastSmtpCode = $smtpCode;
        $this->lastEnhancedStatus = $enhancedStatus;
        $this->lastError = mb_substr($error, 0, 500);

        return $this;
    }
}
