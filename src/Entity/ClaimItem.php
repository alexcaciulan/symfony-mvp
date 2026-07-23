<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClaimItemKind;
use App\Repository\ClaimItemRepository;
use App\Service\Case\DocumentReferenceNormalizer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One position of the claim, typically one invoice.
 *
 * The dominant real case is a single contract with several invoices, each with
 * its own due date. Interest accrues per position from that position's due date
 * (Civil Code art. 1535); running it on the aggregate from the earliest due date
 * claims more than is owed and is attackable in opposition, so the positions,
 * not the case scalars, are the source of truth for every calculation.
 *
 * `amount` / `currency` are the figures as they appear on the source document.
 * `amountRon` is what the calculators consume: equal to `amount` for a RON
 * position, the BNR-converted value otherwise. It stays null when the rate for
 * the invoice date could not be resolved, and `needsManualFx` then marks the
 * position as excluded from the totals rather than silently converted wrong.
 *
 * `causeReference` carries the title or cause the position arises from (in
 * practice the source contract). It is the grouping key for the competence rule
 * of CPC art. 99: positions on the same cause cumulate, positions on different
 * causes are valued separately.
 */
#[ORM\Entity(repositoryClass: ClaimItemRepository::class)]
#[ORM\Table(name: 'claim_item')]
#[ORM\UniqueConstraint(name: 'uniq_claim_item_case_dedup', columns: ['legal_case_id', 'dedup_key'])]
#[ORM\Index(columns: ['legal_case_id', 'due_date'], name: 'idx_claim_item_case_due_date')]
class ClaimItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class, inversedBy: 'claimItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LegalCase $legalCase;

    /**
     * The debtor this position is owed by. Null on a single-debtor case, where
     * every position belongs to the only debtor; populated once positions have
     * to be told apart (joint debtors on one file).
     */
    #[ORM\ManyToOne(targetEntity: Debtor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Debtor $debtor = null;

    #[ORM\Column(length: 30, enumType: ClaimItemKind::class)]
    private ClaimItemKind $kind = ClaimItemKind::INVOICE;

    /** Series plus number, as printed on the document (e.g. "FF 0012/2025"). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $documentNumber = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $documentDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'RON';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $amountRon = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $exchangeRateDate = null;

    /**
     * True when the BNR rate for this position's date could not be resolved. The
     * position is then excluded from the totals and surfaced to the lawyer,
     * rather than converted at a rate that would misstate the claim.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $needsManualFx = false;

    /**
     * Recorded, never applied. In the absence of an agreement, Civil Code
     * art. 1507-1509 imputes payment to costs, then interest, then capital, so
     * deducting it from the principal here would make the claimant ask for less
     * than is owed on a calculation that contradicts the law. Imputation stays
     * an explicit act of the lawyer.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $paidAmount = '0.00';

    #[ORM\Column(length: 100)]
    private string $dedupKey;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $sourceDocument = null;

    /**
     * Title or cause this position arises from; the grouping key for CPC art. 99.
     * Never truncated on the way in: causeKey() groups on the whole value, so a
     * cut that made two distinct causes share a prefix would move the claim to
     * the wrong court.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $causeReference = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $causeDocument = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $confirmedByLawyer = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $excludedByLawyer = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->dedupKey = '';
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

    public function getDebtor(): ?Debtor
    {
        return $this->debtor;
    }

    public function setDebtor(?Debtor $debtor): static
    {
        $this->debtor = $debtor;

        return $this;
    }

    public function getKind(): ClaimItemKind
    {
        return $this->kind;
    }

    public function setKind(ClaimItemKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): static
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    public function getDocumentDate(): ?\DateTimeImmutable
    {
        return $this->documentDate;
    }

    public function setDocumentDate(?\DateTimeImmutable $documentDate): static
    {
        $this->documentDate = $documentDate;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
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

    public function getAmountRon(): ?string
    {
        return $this->amountRon;
    }

    public function setAmountRon(?string $amountRon): static
    {
        $this->amountRon = $amountRon;

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

    public function needsManualFx(): bool
    {
        return $this->needsManualFx;
    }

    public function setNeedsManualFx(bool $needsManualFx): static
    {
        $this->needsManualFx = $needsManualFx;

        return $this;
    }

    public function getPaidAmount(): string
    {
        return $this->paidAmount;
    }

    public function setPaidAmount(string $paidAmount): static
    {
        $this->paidAmount = $paidAmount;

        return $this;
    }

    public function hasUnimputedPayment(): bool
    {
        return (float) $this->paidAmount > 0.0;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function setDedupKey(string $dedupKey): static
    {
        $this->dedupKey = $dedupKey;

        return $this;
    }

    public function getSourceDocument(): ?Document
    {
        return $this->sourceDocument;
    }

    public function setSourceDocument(?Document $sourceDocument): static
    {
        $this->sourceDocument = $sourceDocument;

        return $this;
    }

    public function getCauseReference(): ?string
    {
        return $this->causeReference;
    }

    public function setCauseReference(?string $causeReference): static
    {
        $this->causeReference = $causeReference;

        return $this;
    }

    public function getCauseDocument(): ?Document
    {
        return $this->causeDocument;
    }

    public function setCauseDocument(?Document $causeDocument): static
    {
        $this->causeDocument = $causeDocument;

        return $this;
    }

    public function isConfirmedByLawyer(): bool
    {
        return $this->confirmedByLawyer;
    }

    public function setConfirmedByLawyer(bool $confirmedByLawyer): static
    {
        $this->confirmedByLawyer = $confirmedByLawyer;

        return $this;
    }

    public function isExcludedByLawyer(): bool
    {
        return $this->excludedByLawyer;
    }

    public function setExcludedByLawyer(bool $excludedByLawyer): static
    {
        $this->excludedByLawyer = $excludedByLawyer;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * A position enters the totals, the petition and the index only when the
     * lawyer has confirmed it, has not excluded it, and its RON value is known.
     */
    public function countsTowardsClaim(): bool
    {
        return $this->confirmedByLawyer
            && !$this->excludedByLawyer
            && !$this->needsManualFx
            && $this->amountRon !== null;
    }

    /**
     * Grouping key for CPC art. 99, empty when the position states no cause.
     *
     * Normalized as aggressively as the invoice-number key: each invoice is read
     * on its own, so one contract routinely arrives spelled "Contract nr.
     * 12/2024" on some pages and "Contract 12/2024" on others. Comparing the raw
     * strings would split one cause in two and drop the claim to the lower court.
     */
    public function causeKey(): string
    {
        return DocumentReferenceNormalizer::normalize($this->causeReference) ?? '';
    }

    /**
     * A credit note reduces the claim, so it carries a negative RON value and
     * accrues nothing. Interest on a sum that was never owed cannot be claimed.
     */
    public function isCreditNote(): bool
    {
        return $this->kind === ClaimItemKind::CREDIT_NOTE;
    }

    /**
     * The signed RON value this position contributes to the principal: negative
     * for a credit note, positive otherwise. Null when the rate is unresolved.
     */
    public function signedAmountRon(): ?float
    {
        if ($this->amountRon === null) {
            return null;
        }

        $value = abs((float) $this->amountRon);

        return $this->isCreditNote() ? -$value : $value;
    }
}
