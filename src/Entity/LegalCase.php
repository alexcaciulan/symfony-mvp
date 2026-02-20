<?php

namespace App\Entity;

use App\Repository\LegalCaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LegalCaseRepository::class)]
#[ORM\HasLifecycleCallbacks]
class LegalCase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, nullable: true, unique: true)]
    private ?string $caseNumber = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'legalCases')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Court::class, inversedBy: 'legalCases')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Court $court = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $currentStep = 1;

    #[ORM\Column(length: 30)]
    private string $status = 'draft';

    // Step 1 - Court selection
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $county = null;

    // Step 2 - Claimant
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $claimantType = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $claimantData = null;

    #[ORM\Column]
    private bool $hasLawyer = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lawyerData = null;

    // Step 3 - Defendants
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $defendants = null;

    // Step 4 - Claim
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $claimAmount = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'RON';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $claimDescription = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $legalBasis = null;

    #[ORM\Column(length: 20)]
    private string $interestType = 'none';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $interestRate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $interestStartDate = null;

    #[ORM\Column]
    private bool $requestCourtCosts = false;

    // Step 5 - Evidence
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidenceDescription = null;

    #[ORM\Column]
    private bool $hasWitnesses = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $witnesses = null;

    #[ORM\Column]
    private bool $requestOralDebate = false;

    // Step 6 - Fees (calculated)
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $courtFee = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $platformFee = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $totalFee = null;

    // Timestamps
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    // Relations
    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'legalCase')]
    private Collection $documents;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'legalCase')]
    private Collection $payments;

    /** @var Collection<int, CaseStatusHistory> */
    #[ORM\OneToMany(targetEntity: CaseStatusHistory::class, mappedBy: 'legalCase')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $statusHistory;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->documents = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();
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

    public function getCaseNumber(): ?string
    {
        return $this->caseNumber;
    }

    public function setCaseNumber(?string $caseNumber): static
    {
        $this->caseNumber = $caseNumber;

        return $this;
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

    public function getCourt(): ?Court
    {
        return $this->court;
    }

    public function setCourt(?Court $court): static
    {
        $this->court = $court;

        return $this;
    }

    public function getCurrentStep(): int
    {
        return $this->currentStep;
    }

    public function setCurrentStep(int $currentStep): static
    {
        $this->currentStep = $currentStep;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCounty(): ?string
    {
        return $this->county;
    }

    public function setCounty(?string $county): static
    {
        $this->county = $county;

        return $this;
    }

    public function getClaimantType(): ?string
    {
        return $this->claimantType;
    }

    public function setClaimantType(?string $claimantType): static
    {
        $this->claimantType = $claimantType;

        return $this;
    }

    public function getClaimantData(): ?array
    {
        return $this->claimantData;
    }

    public function setClaimantData(?array $claimantData): static
    {
        $this->claimantData = $claimantData;

        return $this;
    }

    public function hasLawyer(): bool
    {
        return $this->hasLawyer;
    }

    public function setHasLawyer(bool $hasLawyer): static
    {
        $this->hasLawyer = $hasLawyer;

        return $this;
    }

    public function getLawyerData(): ?array
    {
        return $this->lawyerData;
    }

    public function setLawyerData(?array $lawyerData): static
    {
        $this->lawyerData = $lawyerData;

        return $this;
    }

    public function getDefendants(): ?array
    {
        return $this->defendants;
    }

    public function setDefendants(?array $defendants): static
    {
        $this->defendants = $defendants;

        return $this;
    }

    public function getClaimAmount(): ?string
    {
        return $this->claimAmount;
    }

    public function setClaimAmount(?string $claimAmount): static
    {
        $this->claimAmount = $claimAmount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getClaimDescription(): ?string
    {
        return $this->claimDescription;
    }

    public function setClaimDescription(?string $claimDescription): static
    {
        $this->claimDescription = $claimDescription;

        return $this;
    }

    public function getDueDate(): ?\DateTimeInterface
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeInterface $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getLegalBasis(): ?string
    {
        return $this->legalBasis;
    }

    public function setLegalBasis(?string $legalBasis): static
    {
        $this->legalBasis = $legalBasis;

        return $this;
    }

    public function getInterestType(): string
    {
        return $this->interestType;
    }

    public function setInterestType(string $interestType): static
    {
        $this->interestType = $interestType;

        return $this;
    }

    public function getInterestRate(): ?string
    {
        return $this->interestRate;
    }

    public function setInterestRate(?string $interestRate): static
    {
        $this->interestRate = $interestRate;

        return $this;
    }

    public function getInterestStartDate(): ?\DateTimeInterface
    {
        return $this->interestStartDate;
    }

    public function setInterestStartDate(?\DateTimeInterface $interestStartDate): static
    {
        $this->interestStartDate = $interestStartDate;

        return $this;
    }

    public function isRequestCourtCosts(): bool
    {
        return $this->requestCourtCosts;
    }

    public function setRequestCourtCosts(bool $requestCourtCosts): static
    {
        $this->requestCourtCosts = $requestCourtCosts;

        return $this;
    }

    public function getEvidenceDescription(): ?string
    {
        return $this->evidenceDescription;
    }

    public function setEvidenceDescription(?string $evidenceDescription): static
    {
        $this->evidenceDescription = $evidenceDescription;

        return $this;
    }

    public function hasWitnesses(): bool
    {
        return $this->hasWitnesses;
    }

    public function setHasWitnesses(bool $hasWitnesses): static
    {
        $this->hasWitnesses = $hasWitnesses;

        return $this;
    }

    public function getWitnesses(): ?array
    {
        return $this->witnesses;
    }

    public function setWitnesses(?array $witnesses): static
    {
        $this->witnesses = $witnesses;

        return $this;
    }

    public function isRequestOralDebate(): bool
    {
        return $this->requestOralDebate;
    }

    public function setRequestOralDebate(bool $requestOralDebate): static
    {
        $this->requestOralDebate = $requestOralDebate;

        return $this;
    }

    public function getCourtFee(): ?string
    {
        return $this->courtFee;
    }

    public function setCourtFee(?string $courtFee): static
    {
        $this->courtFee = $courtFee;

        return $this;
    }

    public function getPlatformFee(): ?string
    {
        return $this->platformFee;
    }

    public function setPlatformFee(?string $platformFee): static
    {
        $this->platformFee = $platformFee;

        return $this;
    }

    public function getTotalFee(): ?string
    {
        return $this->totalFee;
    }

    public function setTotalFee(?string $totalFee): static
    {
        $this->totalFee = $totalFee;

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

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    /** @return Collection<int, Payment> */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /** @return Collection<int, CaseStatusHistory> */
    public function getStatusHistory(): Collection
    {
        return $this->statusHistory;
    }

    public function getClaimantName(): ?string
    {
        return $this->claimantData['name'] ?? null;
    }

    public function getFirstDefendantName(): ?string
    {
        $defendants = $this->defendants ?? [];

        return $defendants[0]['name'] ?? null;
    }

    public function __toString(): string
    {
        return 'Dosar #' . ($this->id ?? 'nou');
    }
}
