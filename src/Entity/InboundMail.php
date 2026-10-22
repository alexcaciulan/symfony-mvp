<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InboundAuthVerdict;
use App\Enum\InboundClassification;
use App\Enum\InboundMatchConfidence;
use App\Enum\InboundMatchMethod;
use App\Enum\InboundReviewStatus;
use App\Repository\InboundMailRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message received on the platform mailbox or a VERP bounce address, or a
 * receipt the lawyer uploaded. Only headers and the outcome of the processing are
 * stored here: the raw bytes stay in a file checked against their hash, and no
 * HTML body is ever kept or rendered. A message is information for the lawyer,
 * never a procedural act: the platform address is not a procedural address.
 */
#[ORM\Entity(repositoryClass: InboundMailRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_inbound_mail_source', columns: ['source', 'source_id'])]
#[ORM\Index(name: 'idx_inbound_mail_message_id', columns: ['message_id'])]
#[ORM\Index(name: 'idx_inbound_mail_sha', columns: ['raw_sha256'])]
#[ORM\Index(name: 'idx_inbound_mail_purge', columns: ['classification', 'purge_after'])]
#[ORM\Index(name: 'idx_inbound_mail_review', columns: ['review_status'])]
class InboundMail
{
    public const SOURCE_MANUAL = 'manual';

    public const MIME_EML = 'eml';
    public const MIME_PDF = 'pdf';
    public const MIME_IMAGE = 'image';

    private const DIAGNOSTIC_MAX = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $source;

    #[ORM\Column(length: 191)]
    private string $sourceId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $inReplyTo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $referencesHeader = null;

    #[ORM\Column(length: 254, nullable: true)]
    private ?string $fromAddress = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $toAddress = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $ccAddresses = [];

    #[ORM\Column(length: 254, nullable: true)]
    private ?string $deliveredTo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    /** Relative to the uploads directory. */
    #[ORM\Column(length: 255)]
    private string $rawPath;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $rawSha256;

    #[ORM\Column]
    private int $rawBytes;

    #[ORM\Column(length: 10)]
    private string $rawMime = self::MIME_EML;

    /** The lawyer's file name, for a manual upload only. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 20, nullable: true, enumType: InboundAuthVerdict::class)]
    private ?InboundAuthVerdict $authVerdict = null;

    #[ORM\Column(length: 30, nullable: true, enumType: InboundClassification::class)]
    private ?InboundClassification $classification = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $dsnStatus = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $dsnAction = null;

    #[ORM\Column(length: 254, nullable: true)]
    private ?string $dsnFinalRecipient = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dsnDiagnostic = null;

    #[ORM\ManyToOne(targetEntity: FilingEmailPart::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?FilingEmailPart $matchedPart = null;

    #[ORM\ManyToOne(targetEntity: FilingSubmission::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?FilingSubmission $matchedSubmission = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?LegalCase $legalCase = null;

    #[ORM\Column(length: 20, nullable: true, enumType: InboundMatchMethod::class)]
    private ?InboundMatchMethod $matchMethod = null;

    #[ORM\Column(length: 20, nullable: true, enumType: InboundMatchConfidence::class)]
    private ?InboundMatchConfidence $matchConfidence = null;

    #[ORM\Column(length: 20, enumType: InboundReviewStatus::class)]
    private InboundReviewStatus $reviewStatus = InboundReviewStatus::NONE;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reviewReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $forwardedAt = null;

    /** Set only for messages nothing ties to a case (unknown, spam). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $purgeAfter = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $source, string $sourceId, string $rawPath, string $rawSha256, int $rawBytes, \DateTimeImmutable $receivedAt, \DateTimeImmutable $createdAt)
    {
        $this->source = $source;
        $this->sourceId = $sourceId;
        $this->rawPath = $rawPath;
        $this->rawSha256 = $rawSha256;
        $this->rawBytes = $rawBytes;
        $this->receivedAt = $receivedAt;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    /**
     * @param list<string> $cc
     */
    public function setHeaders(?string $messageId, ?string $inReplyTo, ?string $references, ?string $from, ?string $to, array $cc, ?string $deliveredTo, ?string $subject): static
    {
        $this->messageId = self::cut($messageId, 255);
        $this->inReplyTo = self::cut($inReplyTo, 255);
        $this->referencesHeader = $references;
        $this->fromAddress = self::cut($from !== null ? mb_strtolower($from) : null, 254);
        $this->toAddress = self::cut($to !== null ? mb_strtolower($to) : null, 1000);
        $this->ccAddresses = array_values(array_map('mb_strtolower', $cc));
        $this->deliveredTo = self::cut($deliveredTo !== null ? mb_strtolower($deliveredTo) : null, 254);
        $this->subject = self::cut($subject, 255);

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function getReferencesHeader(): ?string
    {
        return $this->referencesHeader;
    }

    public function getFromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function getToAddress(): ?string
    {
        return $this->toAddress;
    }

    /** @return list<string> */
    public function getCcAddresses(): array
    {
        return $this->ccAddresses;
    }

    public function getDeliveredTo(): ?string
    {
        return $this->deliveredTo;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getRawPath(): string
    {
        return $this->rawPath;
    }

    public function getRawSha256(): string
    {
        return $this->rawSha256;
    }

    public function getRawBytes(): int
    {
        return $this->rawBytes;
    }

    public function getRawMime(): string
    {
        return $this->rawMime;
    }

    public function setRawMime(string $rawMime): static
    {
        $this->rawMime = $rawMime;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $originalFilename): static
    {
        $this->originalFilename = self::cut($originalFilename, 255);

        return $this;
    }

    public function getAuthVerdict(): ?InboundAuthVerdict
    {
        return $this->authVerdict;
    }

    public function setAuthVerdict(?InboundAuthVerdict $authVerdict): static
    {
        $this->authVerdict = $authVerdict;

        return $this;
    }

    public function getClassification(): ?InboundClassification
    {
        return $this->classification;
    }

    public function setClassification(?InboundClassification $classification): static
    {
        $this->classification = $classification;

        return $this;
    }

    public function setDsn(?string $status, ?string $action, ?string $finalRecipient, ?string $diagnostic): static
    {
        $this->dsnStatus = self::cut($status, 20);
        $this->dsnAction = self::cut($action !== null ? mb_strtolower($action) : null, 20);
        $this->dsnFinalRecipient = self::cut($finalRecipient !== null ? mb_strtolower($finalRecipient) : null, 254);
        $this->dsnDiagnostic = self::cut($diagnostic, self::DIAGNOSTIC_MAX);

        return $this;
    }

    public function getDsnStatus(): ?string
    {
        return $this->dsnStatus;
    }

    public function getDsnAction(): ?string
    {
        return $this->dsnAction;
    }

    public function getDsnFinalRecipient(): ?string
    {
        return $this->dsnFinalRecipient;
    }

    public function getDsnDiagnostic(): ?string
    {
        return $this->dsnDiagnostic;
    }

    public function match(?FilingEmailPart $part, ?FilingSubmission $submission, ?InboundMatchMethod $method, ?InboundMatchConfidence $confidence): static
    {
        $this->matchedPart = $part;
        $this->matchedSubmission = $submission ?? $part?->getSubmission();
        $this->legalCase = $this->matchedSubmission?->getLegalCase();
        $this->matchMethod = $method;
        $this->matchConfidence = $confidence;

        return $this;
    }

    public function getMatchedPart(): ?FilingEmailPart
    {
        return $this->matchedPart;
    }

    public function getMatchedSubmission(): ?FilingSubmission
    {
        return $this->matchedSubmission;
    }

    public function getLegalCase(): ?LegalCase
    {
        return $this->legalCase;
    }

    public function getMatchMethod(): ?InboundMatchMethod
    {
        return $this->matchMethod;
    }

    public function getMatchConfidence(): ?InboundMatchConfidence
    {
        return $this->matchConfidence;
    }

    public function isCertain(): bool
    {
        return $this->matchConfidence === InboundMatchConfidence::CERTAIN && $this->matchedSubmission !== null;
    }

    public function getReviewStatus(): InboundReviewStatus
    {
        return $this->reviewStatus;
    }

    public function getReviewReason(): ?string
    {
        return $this->reviewReason;
    }

    public function markForReview(string $reason): static
    {
        $this->reviewStatus = InboundReviewStatus::PENDING;
        $this->reviewReason = self::cut($reason, 120);

        return $this;
    }

    public function resolveReview(): static
    {
        $this->reviewStatus = InboundReviewStatus::RESOLVED;

        return $this;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function markProcessed(\DateTimeImmutable $at, ?\DateTimeImmutable $purgeAfter): static
    {
        $this->processedAt = $at;
        $this->purgeAfter = $purgeAfter;

        return $this;
    }

    public function getForwardedAt(): ?\DateTimeImmutable
    {
        return $this->forwardedAt;
    }

    public function markForwarded(\DateTimeImmutable $at): static
    {
        $this->forwardedAt = $at;

        return $this;
    }

    public function getPurgeAfter(): ?\DateTimeImmutable
    {
        return $this->purgeAfter;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return sprintf('#%d %s', (int) $this->id, (string) $this->subject);
    }

    private static function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
