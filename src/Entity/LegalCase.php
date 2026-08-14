<?php

namespace App\Entity;

use App\Enum\CaseStatus;
use App\Enum\DebitAcknowledgedStatus;
use App\Enum\ExtractionMode;
use App\Enum\PaymentNoticeCommunicationMethod;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Enum\StampDutyStatus;
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

    /**
     * DENORMALIZATION. The source of truth for the claimed sum is the set of
     * {@see ClaimItem} positions; this holds their total in RON, maintained by
     * {@see \App\Service\Case\ClaimTotalsService::recalculate()}. Never assign it
     * directly on a case that has positions: the next recalculation overwrites it
     * and the two figures silently disagree in the meantime.
     */
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

    #[ORM\Column(length: 30, enumType: StampDutyStatus::class, options: ['default' => 'NEACHITATA'])]
    private StampDutyStatus $stampDutyStatus = StampDutyStatus::NEACHITATA;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $stampDutyPaidAt = null;

    /**
     * Amount actually paid, as it appears on the proof. Deliberately distinct from
     * `stampDuty`, which is the amount computed when the case was created: the two
     * can diverge if the statutory duty changes between filing and payment, and a
     * dispute is about what was paid, not about what we once calculated.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $stampDutyPaidAmount = null;

    /**
     * Who appears as payer on the proof. The duty is owed by the claimant (OUG
     * 80/2013 art. 40 alin. 1) and art. 40 alin. 3 presumes payment from a transfer
     * order "signed by the debtor of the duty", so a proof naming someone else is
     * a risk worth surfacing to the lawyer.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stampDutyPayerName = null;

    /**
     * Snapshot of the UAT we told the lawyer to pay into. Kept even though the
     * creditor's office is known, because the office can move: without this we
     * cannot reconstruct the advice we gave, and paying into the wrong UAT's
     * account is treated as non-payment.
     */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $stampDutyUat = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stampDutyPaymentReference = null;

    /** Statutory basis for the amount, frozen at payment time for retroactive justification. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $stampDutyLawVersion = null;

    /**
     * Modul de calcul al accesoriilor pentru somație. Fallback la render:
     * {@see PenaltyType::LEGAL_PENALIZATOARE} (dobândă legală BNR + 8).
     */
    #[ORM\Column(length: 30, nullable: true, enumType: PenaltyType::class)]
    private ?PenaltyType $penaltyType = null;

    /** Rată zilnică a clauzei penale (ex. 0.100 pentru 0,10%/zi), doar când penaltyType = CONTRACTUAL. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 3, nullable: true)]
    private ?string $contractualPenaltyRate = null;

    /**
     * Referința clauzei penale din contract (ex. „art. 3 din Contract"). AI
     * extraction sometimes returns a longer descriptive phrase, so the column
     * has headroom and the persist path caps it as a final guard.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contractReference = null;

    /**
     * What the claim is for, in the lawyer's own words. Per-invoice wording
     * lives on {@see ClaimItem::$description} and is what the petition itemises;
     * this is the one-line object of the whole claim.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $claimDescription = null;

    /**
     * DENORMALIZATION, kept only so the existing templates still have one
     * invoice to name. Taken from the position with the earliest due date by
     * {@see \App\Service\Case\ClaimTotalsService::recalculate()}. The full list
     * of invoices lives in {@see $claimItems}.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $invoiceNumber = null;

    /** DENORMALIZATION, see {@see $invoiceNumber}. */
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

    /**
     * DENORMALIZATION: the earliest due date across the positions, which is what
     * matters for prescription and for the exigibility guard. Maintained by
     * {@see \App\Service\Case\ClaimTotalsService::recalculate()}; interest is
     * never computed from it once positions exist, because each position accrues
     * from its own due date.
     */
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
     * Date the ruling given on the annulment request was SERVED. Distinct from
     * `rulingCommunicationDate`, which concerns the initial payment order, and from
     * `finalRulingDate`, which is the date that order was PRONOUNCED.
     *
     * When the debtor filed an annulment request, the order does NOT become final on
     * the lapse of the ten days: it becomes final through the rejection of that request
     * (CPC art. 1024 para. 8), and the three years of enforcement limitation run from
     * the service of that second ruling (CPC art. 705 para. 2). The date cannot be
     * derived from anything the application holds, so the lawyer records it; until then
     * the case is listed as a blockage.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annulmentRulingCommunicationDate = null;

    /**
     * Date the enforcement request was filed with the bailiff, accompanied by the
     * enforceable title. That filing is the fact CPC art. 708 para. 1 pt. 2 attaches
     * the interruption of the enforcement limitation to, and the interruption runs
     * from the date of filing, not from the day the case was marked as being in
     * enforcement, so the date is recorded instead of being inferred from the status.
     *
     * Nothing in the application can derive it, so the lawyer states it when he moves
     * the case into enforcement. On its own it no longer closes the enforcement-limitation
     * term: it only silences the alerts on it, because the act has been performed and
     * what is left is the confirmation. The closing waits for
     * {@see self::$enforcementRegistrationNumber}.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $enforcementRequestDate = null;

    /**
     * Registration number the bailiff assigned to the enforcement request. In practice
     * the bailiff registers the request as soon as it is received, so this number is the
     * confirmation that the filing the date above declares actually happened, coming
     * from outside the platform rather than from the lawyer alone.
     *
     * It is what closes the enforcement-limitation term. The term still closes AGAINST
     * the date, not against the moment of registration: the interruption of CPC art. 708
     * para. 1 pt. 2 attaches to the request filed and runs from its date, so anchoring
     * on the registration would move the interruption later, against the creditor.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $enforcementRegistrationNumber = null;

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

    /**
     * Count of consecutive failed portal queries. Incremented on each portal
     * query failure (including Messenger retries), reset to 0 on any successful
     * query. When it reaches the monitoring service threshold, monitoring is
     * deactivated and the lawyer is notified.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $portalConsecutiveFailures = 0;

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

    /**
     * The claim positions, source of truth for the claimed sum.
     *
     * @var Collection<int, ClaimItem>
     */
    #[ORM\OneToMany(targetEntity: ClaimItem::class, mappedBy: 'legalCase', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dueDate' => 'ASC', 'id' => 'ASC'])]
    private Collection $claimItems;

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
        $this->claimItems = new ArrayCollection();
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

    public function getStampDutyStatus(): StampDutyStatus
    {
        return $this->stampDutyStatus;
    }

    public function setStampDutyStatus(StampDutyStatus $stampDutyStatus): static
    {
        $this->stampDutyStatus = $stampDutyStatus;

        return $this;
    }

    public function getStampDutyPaidAt(): ?\DateTimeImmutable
    {
        return $this->stampDutyPaidAt;
    }

    public function setStampDutyPaidAt(?\DateTimeImmutable $stampDutyPaidAt): static
    {
        $this->stampDutyPaidAt = $stampDutyPaidAt;

        return $this;
    }

    public function getStampDutyPaidAmount(): ?string
    {
        return $this->stampDutyPaidAmount;
    }

    public function setStampDutyPaidAmount(?string $stampDutyPaidAmount): static
    {
        $this->stampDutyPaidAmount = $stampDutyPaidAmount;

        return $this;
    }

    public function getStampDutyPayerName(): ?string
    {
        return $this->stampDutyPayerName;
    }

    public function setStampDutyPayerName(?string $stampDutyPayerName): static
    {
        $this->stampDutyPayerName = $stampDutyPayerName;

        return $this;
    }

    public function getStampDutyUat(): ?string
    {
        return $this->stampDutyUat;
    }

    public function setStampDutyUat(?string $stampDutyUat): static
    {
        $this->stampDutyUat = $stampDutyUat;

        return $this;
    }

    public function getStampDutyPaymentReference(): ?string
    {
        return $this->stampDutyPaymentReference;
    }

    public function setStampDutyPaymentReference(?string $stampDutyPaymentReference): static
    {
        $this->stampDutyPaymentReference = $stampDutyPaymentReference;

        return $this;
    }

    public function getStampDutyLawVersion(): ?string
    {
        return $this->stampDutyLawVersion;
    }

    public function setStampDutyLawVersion(?string $stampDutyLawVersion): static
    {
        $this->stampDutyLawVersion = $stampDutyLawVersion;

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

    public function getClaimDescription(): ?string
    {
        return $this->claimDescription;
    }

    public function setClaimDescription(?string $claimDescription): static
    {
        $this->claimDescription = $claimDescription;

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

    public function getAnnulmentRulingCommunicationDate(): ?\DateTimeImmutable
    {
        return $this->annulmentRulingCommunicationDate;
    }

    public function setAnnulmentRulingCommunicationDate(?\DateTimeImmutable $annulmentRulingCommunicationDate): static
    {
        $this->annulmentRulingCommunicationDate = $annulmentRulingCommunicationDate;

        return $this;
    }

    public function getEnforcementRequestDate(): ?\DateTimeImmutable
    {
        return $this->enforcementRequestDate;
    }

    public function setEnforcementRequestDate(?\DateTimeImmutable $enforcementRequestDate): static
    {
        $this->enforcementRequestDate = $enforcementRequestDate;

        return $this;
    }

    public function getEnforcementRegistrationNumber(): ?string
    {
        return $this->enforcementRegistrationNumber;
    }

    public function setEnforcementRegistrationNumber(?string $enforcementRegistrationNumber): static
    {
        $this->enforcementRegistrationNumber = $enforcementRegistrationNumber;

        return $this;
    }

    /**
     * Whether an annulment request was ever filed against the payment order, read from
     * the status history rather than from the current status: the case has usually
     * moved on to DEFINITIVA or EXECUTARE by the time this matters, and the history is
     * the only record that IN_ANULARE was ever entered.
     */
    public function hasPassedThroughAnnulment(): bool
    {
        foreach ($this->statusHistory as $entry) {
            if ($entry->getNewStatus() === CaseStatus::IN_ANULARE->value) {
                return true;
            }
        }

        return false;
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

    public function getPortalConsecutiveFailures(): int
    {
        return $this->portalConsecutiveFailures;
    }

    public function incrementPortalConsecutiveFailures(): static
    {
        ++$this->portalConsecutiveFailures;

        return $this;
    }

    public function resetPortalConsecutiveFailures(): static
    {
        $this->portalConsecutiveFailures = 0;

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

    /** @return Collection<int, ClaimItem> */
    public function getClaimItems(): Collection
    {
        return $this->claimItems;
    }

    public function addClaimItem(ClaimItem $item): static
    {
        if (!$this->claimItems->contains($item)) {
            $this->claimItems->add($item);
            $item->setLegalCase($this);
        }

        return $this;
    }

    public function removeClaimItem(ClaimItem $item): static
    {
        $this->claimItems->removeElement($item);

        return $this;
    }

    /**
     * Positions that enter the totals, the petition and the index.
     *
     * @return list<ClaimItem>
     */
    public function getCountingClaimItems(): array
    {
        $counting = [];
        foreach ($this->claimItems as $item) {
            if ($item->countsTowardsClaim()) {
                $counting[] = $item;
            }
        }

        return $counting;
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
