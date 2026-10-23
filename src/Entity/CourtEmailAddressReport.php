<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CourtEmailReportKind;
use App\Enum\CourtEmailReportReason;
use App\Enum\CourtEmailReportResolution;
use App\Repository\CourtEmailAddressReportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A report that a court address may be wrong, waiting for the admin: a lawyer's
 * report from a case, or a delivery report saying the mailbox does not exist. A
 * report never takes the address out of use by itself; only the admin decides,
 * so one account cannot change where every other lawyer's filings go.
 */
#[ORM\Entity(repositoryClass: CourtEmailAddressReportRepository::class)]
#[ORM\Index(name: 'idx_court_email_report_open', columns: ['address_id', 'resolved_at'])]
class CourtEmailAddressReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CourtEmailAddress::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CourtEmailAddress $address;

    #[ORM\Column(length: 20, enumType: CourtEmailReportKind::class)]
    private CourtEmailReportKind $kind;

    #[ORM\Column(length: 30, enumType: CourtEmailReportReason::class)]
    private CourtEmailReportReason $reason;

    #[ORM\ManyToOne(targetEntity: LegalCase::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LegalCase $legalCase = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reportedBy = null;

    #[ORM\ManyToOne(targetEntity: InboundMail::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?InboundMail $inboundMail = null;

    #[ORM\ManyToOne(targetEntity: FilingSubmission::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FilingSubmission $submission = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $enhancedStatus = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(length: 20, nullable: true, enumType: CourtEmailReportResolution::class)]
    private ?CourtEmailReportResolution $resolution = null;

    public function __construct(CourtEmailAddress $address, CourtEmailReportKind $kind, CourtEmailReportReason $reason, \DateTimeImmutable $createdAt)
    {
        $this->address = $address;
        $this->kind = $kind;
        $this->reason = $reason;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAddress(): CourtEmailAddress
    {
        return $this->address;
    }

    public function getKind(): CourtEmailReportKind
    {
        return $this->kind;
    }

    public function getReason(): CourtEmailReportReason
    {
        return $this->reason;
    }

    public function getLegalCase(): ?LegalCase
    {
        return $this->legalCase;
    }

    public function getReportedBy(): ?User
    {
        return $this->reportedBy;
    }

    public function getInboundMail(): ?InboundMail
    {
        return $this->inboundMail;
    }

    public function getSubmission(): ?FilingSubmission
    {
        return $this->submission;
    }

    public function getEnhancedStatus(): ?string
    {
        return $this->enhancedStatus;
    }

    public function setContext(?LegalCase $case, ?User $reportedBy, ?FilingSubmission $submission = null, ?InboundMail $mail = null, ?string $enhancedStatus = null): static
    {
        $this->legalCase = $case;
        $this->reportedBy = $reportedBy;
        $this->submission = $submission;
        $this->inboundMail = $mail;
        $this->enhancedStatus = $enhancedStatus !== null ? mb_substr($enhancedStatus, 0, 10) : null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getResolution(): ?CourtEmailReportResolution
    {
        return $this->resolution;
    }

    public function isOpen(): bool
    {
        return $this->resolvedAt === null;
    }

    public function resolve(User $by, CourtEmailReportResolution $resolution, \DateTimeImmutable $at): static
    {
        $this->resolvedBy = $by;
        $this->resolution = $resolution;
        $this->resolvedAt = $at;

        return $this;
    }
}
