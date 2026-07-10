<?php

namespace App\Entity;

use App\Enum\CaseStatus;
use App\Enum\DebitAcknowledgedStatus;
use App\Enum\ExtractionMode;
use App\Enum\PaymentNoticeCommunicationMethod;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
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

    #[ORM\Column(length: 30, unique: true)]
    private string $caseNumber;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'legalCases')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Court::class, inversedBy: 'legalCases')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Court $court = null;

    #[ORM\ManyToOne(targetEntity: Creditor::class, inversedBy: 'legalCases')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Creditor $creditor = null;

    /** @var Collection<int, Debtor> */
    #[ORM\OneToMany(targetEntity: Debtor::class, mappedBy: 'legalCase', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $debtors;

    #[ORM\Column(length: 30, enumType: CaseStatus::class)]
    private CaseStatus $status = CaseStatus::AMIABIL;

    #[ORM\Column(length: 20, nullable: true, enumType: RelationshipType::class)]
    private ?RelationshipType $relationshipType = null;

    #[ORM\Column(length: 20, nullable: true, enumType: ExtractionMode::class)]
    private ?ExtractionMode $extractionModeOverride = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'RON';

    /**
     * FX conversion audit trail. When the claim was filed in a foreign currency,
     * `amount`/`currency` hold the RON-converted values used in every calculation,
     * while these four keep the original for transparency in the document. All
     * null for native-RON claims.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $originalAmount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $originalCurrency = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $exchangeRateDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $calculatedInterest = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $stampDuty = null;

    /**
     * Modul de calcul al accesoriilor pentru somație. Fallback la render:
     * {@see PenaltyType::LEGAL_PENALIZATOARE} (dobândă legală BNR + 8).
     */
    #[ORM\Column(length: 30, nullable: true, enumType: PenaltyType::class)]
    private ?PenaltyType $penaltyType = null;

    /** Rată zilnică a clauzei penale (ex. 0.100 pentru 0,10%/zi), doar când penaltyType = CONTRACTUAL. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 3, nullable: true)]
    private ?string $contractualPenaltyRate = null;

    /** Referința clauzei penale din contract (ex. „art. 3 din Contract"). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $contractReference = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $invoiceDate = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $contractNumber = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $contractDate = null;

    /** Onorariu fix avocațial (cheltuieli de recuperare, Cod civil art. 1531). */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $legalCostsFixed = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $legalCostsCurrency = 'EUR';

    /** Onorariu de succes ca procent aplicat sumelor recuperate. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $legalCostsSuccessPercent = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $paymentNoticeDate = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $courtCaseNumber = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $hearingDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $finalRulingDate = null;

    /**
     * Data la care ordonanța de plată a fost COMUNICATĂ debitorului (NU data
     * pronunțării). De la această dată curge termenul de 10 zile pentru cererea
     * în anulare (CPC art. 1024 alin. 1) și, după prorogare CPC art. 181 alin.
     * 2 + buffer de 5 zile lucrătoare, tranziția automată la `DEFINITIVA` (cron-ul de
     * verificare a termenelor). Populat manual de avocat sau extras din portal.just.ro.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rulingCommunicationDate = null;

    /**
     * Data la care debitorul a PRIMIT somația (confirmată prin AR poștal sau
     * proces-verbal de comunicare al executorului), NU data expedierii. De la
     * această dată curge termenul de 15 zile pentru plată (CPC art. 1015 alin.
     * 1). Folosită pentru recalculul termenului `RASPUNS_SOMATIE` și pentru
     * guard-ul de generare a cererii OP.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paymentNoticeCommunicationDate = null;

    /** Modalitatea de comunicare a somației (executor sau Poșta Română R+CD+AR). */
    #[ORM\Column(length: 20, enumType: PaymentNoticeCommunicationMethod::class, nullable: true)]
    private ?PaymentNoticeCommunicationMethod $paymentNoticeCommunicationMethod = null;

    /** Acordul explicit al avocatului pentru generarea cererii de ordonanță de plată. */
    #[ORM\Column(nullable: true)]
    private ?bool $opGenerationConsent = null;

    /** Statusul debitului confirmat de avocat înainte de generarea OP (parțial / neachitat). */
    #[ORM\Column(length: 20, enumType: DebitAcknowledgedStatus::class, nullable: true)]
    private ?DebitAcknowledgedStatus $debitAcknowledgedStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPortalCheckAt = null;

    /**
     * Flag de activare a monitorizării zilnice portal.just.ro (Pas 6.1). Setat
     * `true` când avocatul introduce numărul de dosar al instanței și pornește
     * monitorizarea. Distinct de `courtCaseNumber`: permite oprirea/repornirea
     * monitorizării fără a șterge numărul de dosar. Filtru în
     * {@see \App\Repository\LegalCaseRepository::findActiveForMonitoring()}.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $portalMonitoringActive = false;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'legalCase', fetch: 'EXTRA_LAZY')]
    private Collection $documents;

    /** @var Collection<int, CaseStatusHistory> */
    #[ORM\OneToMany(targetEntity: CaseStatusHistory::class, mappedBy: 'legalCase')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $statusHistory;

    /** @var Collection<int, CourtPortalEvent> */
    #[ORM\OneToMany(targetEntity: CourtPortalEvent::class, mappedBy: 'legalCase', fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['eventDate' => 'DESC'])]
    private Collection $portalEvents;

    /** @var Collection<int, LegalDeadline> */
    #[ORM\OneToMany(targetEntity: LegalDeadline::class, mappedBy: 'legalCase', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['deadlineDate' => 'ASC'])]
    private Collection $deadlines;

    public function __construct()
    {
        $this->caseNumber = sprintf('LR-%d-%04d', time(), random_int(0, 9999));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->documents = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();
        $this->portalEvents = new ArrayCollection();
        $this->debtors = new ArrayCollection();
        $this->deadlines = new ArrayCollection();
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

    public function getCaseNumber(): string
    {
        return $this->caseNumber;
    }

    public function setCaseNumber(string $caseNumber): static
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

    public function getCreditor(): ?Creditor
    {
        return $this->creditor;
    }

    public function setCreditor(?Creditor $creditor): static
    {
        $this->creditor = $creditor;

        return $this;
    }

    /** @return Collection<int, Debtor> */
    public function getDebtors(): Collection
    {
        return $this->debtors;
    }

    public function addDebtor(Debtor $debtor): static
    {
        if (!$this->debtors->contains($debtor)) {
            $this->debtors->add($debtor);
            $debtor->setLegalCase($this);
        }

        return $this;
    }

    public function removeDebtor(Debtor $debtor): static
    {
        $this->debtors->removeElement($debtor);

        return $this;
    }

    public function getStatus(): CaseStatus
    {
        return $this->status;
    }

    public function setStatus(CaseStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRelationshipType(): ?RelationshipType
    {
        return $this->relationshipType;
    }

    public function setRelationshipType(?RelationshipType $relationshipType): static
    {
        $this->relationshipType = $relationshipType;

        return $this;
    }

    public function getExtractionModeOverride(): ?ExtractionMode
    {
        return $this->extractionModeOverride;
    }

    public function setExtractionModeOverride(?ExtractionMode $extractionModeOverride): static
    {
        $this->extractionModeOverride = $extractionModeOverride;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): static
    {
        $this->amount = $amount;

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

    public function getOriginalAmount(): ?string
    {
        return $this->originalAmount;
    }

    public function setOriginalAmount(?string $originalAmount): static
    {
        $this->originalAmount = $originalAmount;

        return $this;
    }

    public function getOriginalCurrency(): ?string
    {
        return $this->originalCurrency;
    }

    public function setOriginalCurrency(?string $originalCurrency): static
    {
        $this->originalCurrency = $originalCurrency;

        return $this;
    }

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(?string $exchangeRate): static
    {
        $this->exchangeRate = $exchangeRate;

        return $this;
    }

    public function getExchangeRateDate(): ?\DateTimeImmutable
    {
        return $this->exchangeRateDate;
    }

    public function setExchangeRateDate(?\DateTimeImmutable $exchangeRateDate): static
    {
        $this->exchangeRateDate = $exchangeRateDate;

        return $this;
    }

    public function getCalculatedInterest(): ?string
    {
        return $this->calculatedInterest;
    }

    public function setCalculatedInterest(?string $calculatedInterest): static
    {
        $this->calculatedInterest = $calculatedInterest;

        return $this;
    }

    public function getStampDuty(): ?string
    {
        return $this->stampDuty;
    }

    public function setStampDuty(?string $stampDuty): static
    {
        $this->stampDuty = $stampDuty;

        return $this;
    }

    public function getPenaltyType(): ?PenaltyType
    {
        return $this->penaltyType;
    }

    public function setPenaltyType(?PenaltyType $penaltyType): static
    {
        $this->penaltyType = $penaltyType;

        return $this;
    }

    public function getContractualPenaltyRate(): ?string
    {
        return $this->contractualPenaltyRate;
    }

    public function setContractualPenaltyRate(?string $contractualPenaltyRate): static
    {
        $this->contractualPenaltyRate = $contractualPenaltyRate;

        return $this;
    }

    public function getContractReference(): ?string
    {
        return $this->contractReference;
    }

    public function setContractReference(?string $contractReference): static
    {
        $this->contractReference = $contractReference;

        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): static
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getInvoiceDate(): ?\DateTimeInterface
    {
        return $this->invoiceDate;
    }

    public function setInvoiceDate(?\DateTimeInterface $invoiceDate): static
    {
        $this->invoiceDate = $invoiceDate;

        return $this;
    }

    public function getContractNumber(): ?string
    {
        return $this->contractNumber;
    }

    public function setContractNumber(?string $contractNumber): static
    {
        $this->contractNumber = $contractNumber;

        return $this;
    }

    public function getContractDate(): ?\DateTimeInterface
    {
        return $this->contractDate;
    }

    public function setContractDate(?\DateTimeInterface $contractDate): static
    {
        $this->contractDate = $contractDate;

        return $this;
    }

    public function getLegalCostsFixed(): ?string
    {
        return $this->legalCostsFixed;
    }

    public function setLegalCostsFixed(?string $legalCostsFixed): static
    {
        $this->legalCostsFixed = $legalCostsFixed;

        return $this;
    }

    public function getLegalCostsCurrency(): ?string
    {
        return $this->legalCostsCurrency;
    }

    public function setLegalCostsCurrency(?string $legalCostsCurrency): static
    {
        $this->legalCostsCurrency = $legalCostsCurrency;

        return $this;
    }

    public function getLegalCostsSuccessPercent(): ?string
    {
        return $this->legalCostsSuccessPercent;
    }

    public function setLegalCostsSuccessPercent(?string $legalCostsSuccessPercent): static
    {
        $this->legalCostsSuccessPercent = $legalCostsSuccessPercent;

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

    public function getPaymentNoticeDate(): ?\DateTimeInterface
    {
        return $this->paymentNoticeDate;
    }

    public function setPaymentNoticeDate(?\DateTimeInterface $paymentNoticeDate): static
    {
        $this->paymentNoticeDate = $paymentNoticeDate;

        return $this;
    }

    public function getCourtCaseNumber(): ?string
    {
        return $this->courtCaseNumber;
    }

    public function setCourtCaseNumber(?string $courtCaseNumber): static
    {
        $this->courtCaseNumber = $courtCaseNumber;

        return $this;
    }

    public function getHearingDate(): ?\DateTimeInterface
    {
        return $this->hearingDate;
    }

    public function setHearingDate(?\DateTimeInterface $hearingDate): static
    {
        $this->hearingDate = $hearingDate;

        return $this;
    }

    public function getFinalRulingDate(): ?\DateTimeInterface
    {
        return $this->finalRulingDate;
    }

    public function setFinalRulingDate(?\DateTimeInterface $finalRulingDate): static
    {
        $this->finalRulingDate = $finalRulingDate;

        return $this;
    }

    public function getRulingCommunicationDate(): ?\DateTimeImmutable
    {
        return $this->rulingCommunicationDate;
    }

    public function setRulingCommunicationDate(?\DateTimeImmutable $rulingCommunicationDate): static
    {
        $this->rulingCommunicationDate = $rulingCommunicationDate;

        return $this;
    }

    public function getPaymentNoticeCommunicationDate(): ?\DateTimeImmutable
    {
        return $this->paymentNoticeCommunicationDate;
    }

    public function setPaymentNoticeCommunicationDate(?\DateTimeImmutable $paymentNoticeCommunicationDate): static
    {
        $this->paymentNoticeCommunicationDate = $paymentNoticeCommunicationDate;

        return $this;
    }

    public function getPaymentNoticeCommunicationMethod(): ?PaymentNoticeCommunicationMethod
    {
        return $this->paymentNoticeCommunicationMethod;
    }

    public function setPaymentNoticeCommunicationMethod(?PaymentNoticeCommunicationMethod $paymentNoticeCommunicationMethod): static
    {
        $this->paymentNoticeCommunicationMethod = $paymentNoticeCommunicationMethod;

        return $this;
    }

    public function getOpGenerationConsent(): ?bool
    {
        return $this->opGenerationConsent;
    }

    public function setOpGenerationConsent(?bool $opGenerationConsent): static
    {
        $this->opGenerationConsent = $opGenerationConsent;

        return $this;
    }

    public function getDebitAcknowledgedStatus(): ?DebitAcknowledgedStatus
    {
        return $this->debitAcknowledgedStatus;
    }

    public function setDebitAcknowledgedStatus(?DebitAcknowledgedStatus $debitAcknowledgedStatus): static
    {
        $this->debitAcknowledgedStatus = $debitAcknowledgedStatus;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

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

    public function markAsDeleted(): static
    {
        $this->deletedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLastPortalCheckAt(): ?\DateTimeImmutable
    {
        return $this->lastPortalCheckAt;
    }

    public function setLastPortalCheckAt(?\DateTimeImmutable $lastPortalCheckAt): static
    {
        $this->lastPortalCheckAt = $lastPortalCheckAt;

        return $this;
    }

    public function isPortalMonitoringActive(): bool
    {
        return $this->portalMonitoringActive;
    }

    public function setPortalMonitoringActive(bool $portalMonitoringActive): static
    {
        $this->portalMonitoringActive = $portalMonitoringActive;

        return $this;
    }

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    /**
     * Keeps the inverse side in sync so freshly generated documents are visible
     * within the same request, even if the EXTRA_LAZY collection was already
     * initialized (e.g. the opis generator reads it mid-transaction).
     */
    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setLegalCase($this);
        }

        return $this;
    }

    /** @return Collection<int, CaseStatusHistory> */
    public function getStatusHistory(): Collection
    {
        return $this->statusHistory;
    }

    /** @return Collection<int, CourtPortalEvent> */
    public function getPortalEvents(): Collection
    {
        return $this->portalEvents;
    }

    /** @return Collection<int, LegalDeadline> */
    public function getDeadlines(): Collection
    {
        return $this->deadlines;
    }

    public function addDeadline(LegalDeadline $deadline): static
    {
        if (!$this->deadlines->contains($deadline)) {
            $this->deadlines->add($deadline);
            $deadline->setLegalCase($this);
        }

        return $this;
    }

    public function removeDeadline(LegalDeadline $deadline): static
    {
        $this->deadlines->removeElement($deadline);

        return $this;
    }

    public function __toString(): string
    {
        return $this->caseNumber;
    }
}
