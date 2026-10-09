<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PortalCaseMatchSource;
use App\Enum\PortalCaseMatchStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * A court case the portal search matched to a case, and what the lawyer decided
 * about it. The case's confirmed number stays on LegalCase::courtCaseNumber;
 * this keeps the proposals, so a number set aside is never proposed again and
 * the case shows where its number came from.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_portal_case_match_number', columns: ['legal_case_id', 'court_case_number'])]
class PortalCaseMatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class, inversedBy: 'portalCaseMatches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LegalCase $legalCase;

    #[ORM\Column(length: 50)]
    private string $courtCaseNumber;

    #[ORM\Column(length: 20, enumType: PortalCaseMatchStatus::class)]
    private PortalCaseMatchStatus $status = PortalCaseMatchStatus::PROPOSED;

    #[ORM\Column(length: 20, enumType: PortalCaseMatchSource::class)]
    private PortalCaseMatchSource $source;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Null when the decision followed from a number confirmed elsewhere, or the user is gone. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    public function __construct(LegalCase $legalCase, string $courtCaseNumber, PortalCaseMatchSource $source)
    {
        $this->legalCase = $legalCase;
        $this->courtCaseNumber = $courtCaseNumber;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLegalCase(): LegalCase
    {
        return $this->legalCase;
    }

    public function getCourtCaseNumber(): string
    {
        return $this->courtCaseNumber;
    }

    public function getStatus(): PortalCaseMatchStatus
    {
        return $this->status;
    }

    public function getSource(): PortalCaseMatchSource
    {
        return $this->source;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function isProposed(): bool
    {
        return $this->status === PortalCaseMatchStatus::PROPOSED;
    }

    public function isDismissed(): bool
    {
        return $this->status === PortalCaseMatchStatus::DISMISSED;
    }

    public function accept(?User $by): void
    {
        $this->decide(PortalCaseMatchStatus::ACCEPTED, $by);
    }

    public function dismiss(?User $by): void
    {
        $this->decide(PortalCaseMatchStatus::DISMISSED, $by);
    }

    private function decide(PortalCaseMatchStatus $status, ?User $by): void
    {
        $this->status = $status;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decidedBy = $by;
    }
}
