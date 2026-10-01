<?php

namespace App\Entity;

use App\Enum\AnafStatus;
use App\Enum\PersonType;
use App\Repository\LegalCaseDebtorRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A debtor's part in one case: which company, in which position (0 is the
 * debtor the somatie is addressed to), and what was checked about it for this
 * case. The checks stay here, never on the company, because they are true at a
 * date and for a filing, not for the company in general.
 *
 * The identity getters read through to the company, so templates and services
 * keep reading `debtor.name` on a case. There are no identity setters here on
 * purpose: editing a case must not rewrite the company for the lawyer's other
 * cases by accident; the company is written through {@see Debtor} only.
 */
#[ORM\Entity(repositoryClass: LegalCaseDebtorRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_lcd_case_debtor', columns: ['legal_case_id', 'debtor_id'])]
class LegalCaseDebtor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class, inversedBy: 'debtors')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LegalCase $legalCase;

    #[ORM\ManyToOne(targetEntity: Debtor::class, inversedBy: 'legalCaseLinks')]
    #[ORM\JoinColumn(nullable: false)]
    private Debtor $debtor;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(length: 20, nullable: true, enumType: AnafStatus::class)]
    private ?AnafStatus $anafStatus = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $anafCheckedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $inInsolvency = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $insolvencyCheckedAt = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $bpiProofDocument = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bpiVerifiedNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Debtor $debtor)
    {
        $this->debtor = $debtor;
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

    public function getDebtor(): Debtor
    {
        return $this->debtor;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPersonType(): PersonType
    {
        return $this->debtor->getPersonType();
    }

    public function getName(): string
    {
        return $this->debtor->getName();
    }

    public function getCui(): ?string
    {
        return $this->debtor->getCui();
    }

    public function getPersonalId(): ?string
    {
        return $this->debtor->getPersonalId();
    }

    public function getOnrcNumber(): ?string
    {
        return $this->debtor->getOnrcNumber();
    }

    public function getAddress(): string
    {
        return $this->debtor->getAddress();
    }

    public function getAddressCounty(): ?string
    {
        return $this->debtor->getAddressCounty();
    }

    public function getAddressLocality(): ?string
    {
        return $this->debtor->getAddressLocality();
    }

    public function getAdministrator(): ?string
    {
        return $this->debtor->getAdministrator();
    }

    public function getEmail(): ?string
    {
        return $this->debtor->getEmail();
    }

    public function getPhone(): ?string
    {
        return $this->debtor->getPhone();
    }

    public function getIban(): ?string
    {
        return $this->debtor->getIban();
    }

    public function getAnafStatus(): ?AnafStatus
    {
        return $this->anafStatus;
    }

    public function setAnafStatus(?AnafStatus $anafStatus): static
    {
        $this->anafStatus = $anafStatus;

        return $this;
    }

    public function getAnafCheckedAt(): ?\DateTimeImmutable
    {
        return $this->anafCheckedAt;
    }

    public function setAnafCheckedAt(?\DateTimeImmutable $anafCheckedAt): static
    {
        $this->anafCheckedAt = $anafCheckedAt;

        return $this;
    }

    public function isInInsolvency(): bool
    {
        return $this->inInsolvency;
    }

    public function setInInsolvency(bool $inInsolvency): static
    {
        $this->inInsolvency = $inInsolvency;

        return $this;
    }

    public function getInsolvencyCheckedAt(): ?\DateTimeImmutable
    {
        return $this->insolvencyCheckedAt;
    }

    public function setInsolvencyCheckedAt(?\DateTimeImmutable $insolvencyCheckedAt): static
    {
        $this->insolvencyCheckedAt = $insolvencyCheckedAt;

        return $this;
    }

    public function getBpiProofDocument(): ?Document
    {
        return $this->bpiProofDocument;
    }

    public function setBpiProofDocument(?Document $bpiProofDocument): static
    {
        $this->bpiProofDocument = $bpiProofDocument;

        return $this;
    }

    public function getBpiVerifiedNote(): ?string
    {
        return $this->bpiVerifiedNote;
    }

    public function setBpiVerifiedNote(?string $bpiVerifiedNote): static
    {
        $this->bpiVerifiedNote = $bpiVerifiedNote;

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

    public function __toString(): string
    {
        return $this->getName();
    }
}
