<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingEvidenceStatus;
use App\Enum\FilingPartStatus;
use App\Repository\FilingSubmissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One transmission of a signed filing package to the court address validated by
 * the lawyer, made by the platform on the lawyer's request. The addresses, the
 * address state and the confirmed statements are snapshots taken when it was
 * requested, so the record keeps saying what was sent even after the address or
 * the profile changes. An SMTP acceptance is not the filing: the filing date stays
 * the lawyer's declaration on the case.
 */
#[ORM\Entity(repositoryClass: FilingSubmissionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_filing_submission_active', columns: ['legal_case_id', 'active_lock'])]
class FilingSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LegalCase $legalCase;

    #[ORM\ManyToOne(targetEntity: FilingPackage::class)]
    #[ORM\JoinColumn(nullable: false)]
    private FilingPackage $package;

    #[ORM\ManyToOne(targetEntity: Court::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Court $court;

    /** Null once a personal address is erased; the snapshots below keep the record. */
    #[ORM\ManyToOne(targetEntity: CourtEmailAddress::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CourtEmailAddress $courtEmailAddress = null;

    /** Where the messages actually go: the court address, or the override outside live mode. */
    #[ORM\Column(length: 254)]
    private string $toAddress;

    #[ORM\Column(length: 254)]
    private string $intendedToAddress;

    #[ORM\Column(length: 254)]
    private string $fromAddress;

    #[ORM\Column(length: 254)]
    private string $ccAddress;

    #[ORM\Column(length: 254)]
    private string $replyToAddress;

    #[ORM\Column(length: 254)]
    private string $envelopeSender;

    #[ORM\Column(length: 26, unique: true, options: ['fixed' => true])]
    private string $token;

    #[ORM\Column(length: 40, enumType: FilingDeliveryStatus::class)]
    private FilingDeliveryStatus $deliveryStatus = FilingDeliveryStatus::QUEUED;

    #[ORM\Column(length: 30, enumType: FilingEvidenceStatus::class)]
    private FilingEvidenceStatus $evidenceStatus = FilingEvidenceStatus::AWAITING_RECEIPT;

    /** 1 while the submission holds the case, null afterwards; unique per case with it. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $activeLock = 1;

    /** @var list<array{key: string, text: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $confirmations = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $addressStateSnapshot = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $initiatedBy;

    #[ORM\Column]
    private \DateTimeImmutable $initiatedAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $initiatedIp = null;

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $initiatedSessionHash = null;

    /** SMTP acceptance of the petition email; never the filing date. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $courtReceiptAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readReceiptAt = null;

    #[ORM\Column(length: 40)]
    private string $templateVersion;

    /** Translation key explaining why the submission stopped for review. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reviewReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** @var Collection<int, FilingEmailPart> */
    #[ORM\OneToMany(targetEntity: FilingEmailPart::class, mappedBy: 'submission', cascade: ['persist'])]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $parts;

    public function __construct(
        LegalCase $legalCase,
        FilingPackage $package,
        Court $court,
        User $initiatedBy,
        string $token,
        string $templateVersion,
        \DateTimeImmutable $initiatedAt,
    ) {
        $this->legalCase = $legalCase;
        $this->package = $package;
        $this->court = $court;
        $this->initiatedBy = $initiatedBy;
        $this->token = $token;
        $this->templateVersion = $templateVersion;
        $this->initiatedAt = $initiatedAt;
        $this->parts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLegalCase(): LegalCase
    {
        return $this->legalCase;
    }

    public function getPackage(): FilingPackage
    {
        return $this->package;
    }

    public function getCourt(): Court
    {
        return $this->court;
    }

    public function getCourtEmailAddress(): ?CourtEmailAddress
    {
        return $this->courtEmailAddress;
    }

    public function setCourtEmailAddress(?CourtEmailAddress $courtEmailAddress): static
    {
        $this->courtEmailAddress = $courtEmailAddress;

        return $this;
    }

    public function setAddresses(string $to, string $intendedTo, string $from, string $cc, string $replyTo, string $envelopeSender): static
    {
        $this->toAddress = $to;
        $this->intendedToAddress = $intendedTo;
        $this->fromAddress = $from;
        $this->ccAddress = $cc;
        $this->replyToAddress = $replyTo;
        $this->envelopeSender = $envelopeSender;

        return $this;
    }

    public function getToAddress(): string
    {
        return $this->toAddress;
    }

    public function getIntendedToAddress(): string
    {
        return $this->intendedToAddress;
    }

    public function isRecipientOverridden(): bool
    {
        return $this->toAddress !== $this->intendedToAddress;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function getCcAddress(): string
    {
        return $this->ccAddress;
    }

    public function getReplyToAddress(): string
    {
        return $this->replyToAddress;
    }

    public function getEnvelopeSender(): string
    {
        return $this->envelopeSender;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getDeliveryStatus(): FilingDeliveryStatus
    {
        return $this->deliveryStatus;
    }

    public function setDeliveryStatus(FilingDeliveryStatus $deliveryStatus): static
    {
        $this->deliveryStatus = $deliveryStatus;

        return $this;
    }

    public function getEvidenceStatus(): FilingEvidenceStatus
    {
        return $this->evidenceStatus;
    }

    public function getActiveLock(): ?int
    {
        return $this->activeLock;
    }

    public function isActive(): bool
    {
        return $this->activeLock !== null;
    }

    public function release(): static
    {
        $this->activeLock = null;

        return $this;
    }

    /** Stops the submission for the lawyer's review; it keeps holding the case. */
    public function markNeedsReview(string $reasonKey): static
    {
        $this->deliveryStatus = FilingDeliveryStatus::NEEDS_REVIEW;
        $this->reviewReason = $reasonKey;

        return $this;
    }

    /** @return list<array{key: string, text: string}> */
    public function getConfirmations(): array
    {
        return $this->confirmations;
    }

    /** @param list<array{key: string, text: string}> $confirmations */
    public function setConfirmations(array $confirmations): static
    {
        $this->confirmations = $confirmations;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAddressStateSnapshot(): array
    {
        return $this->addressStateSnapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function setAddressStateSnapshot(array $snapshot): static
    {
        $this->addressStateSnapshot = $snapshot;

        return $this;
    }

    public function getInitiatedBy(): User
    {
        return $this->initiatedBy;
    }

    public function getInitiatedAt(): \DateTimeImmutable
    {
        return $this->initiatedAt;
    }

    public function getInitiatedIp(): ?string
    {
        return $this->initiatedIp;
    }

    public function getInitiatedSessionHash(): ?string
    {
        return $this->initiatedSessionHash;
    }

    public function setInitiatedFrom(?string $ip, ?string $sessionHash): static
    {
        $this->initiatedIp = $ip;
        $this->initiatedSessionHash = $sessionHash;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getCourtReceiptAt(): ?\DateTimeImmutable
    {
        return $this->courtReceiptAt;
    }

    public function getReadReceiptAt(): ?\DateTimeImmutable
    {
        return $this->readReceiptAt;
    }

    public function getTemplateVersion(): string
    {
        return $this->templateVersion;
    }

    public function getReviewReason(): ?string
    {
        return $this->reviewReason;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function markCancelled(\DateTimeImmutable $at): static
    {
        $this->deliveryStatus = FilingDeliveryStatus::CANCELLED;
        $this->cancelledAt = $at;
        $this->activeLock = null;

        return $this;
    }

    /** @return Collection<int, FilingEmailPart> */
    public function getParts(): Collection
    {
        return $this->parts;
    }

    public function addPart(FilingEmailPart $part): static
    {
        if (!$this->parts->contains($part)) {
            $this->parts->add($part);
        }

        return $this;
    }

    public function getPart(int $sequence): ?FilingEmailPart
    {
        foreach ($this->parts as $part) {
            if ($part->getSequence() === $sequence) {
                return $part;
            }
        }

        return null;
    }

    public function isPetitionAccepted(): bool
    {
        return $this->getPart(1)?->getStatus() === FilingPartStatus::ACCEPTED;
    }

    public function countAcceptedParts(): int
    {
        return $this->parts->filter(static fn (FilingEmailPart $p): bool => $p->getStatus() === FilingPartStatus::ACCEPTED)->count();
    }
}
