<?php

declare(strict_types=1);

namespace App\Entity;

use App\DTO\Billing\PartySnapshot;
use App\Enum\EInvoiceStatus;
use App\Enum\FiscalInvoiceKind;
use App\Enum\FiscalInvoiceStatus;
use App\Repository\FiscalInvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A fiscally valid Romanian invoice, distinct from the internal billing record
 * {@see Invoice} (1:1, nullable link). Carries series/number, a frozen snapshot
 * of both parties, the VAT breakdown, the document lines and the e-Factura (SPV)
 * state. Series/number are allocated at issuance (F2); a DRAFT has neither.
 */
#[ORM\Entity(repositoryClass: FiscalInvoiceRepository::class)]
#[ORM\Table(name: 'fiscal_invoice')]
#[ORM\UniqueConstraint(name: 'uniq_fiscal_invoice_series_number', columns: ['series', 'number'])]
#[ORM\HasLifecycleCallbacks]
class FiscalInvoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // One fiscal invoice per legacy Invoice (unique, nullable: storno rows have
    // no legacy invoice). Guards against a duplicate DRAFT under concurrent retries.
    #[ORM\OneToOne(targetEntity: Invoice::class, inversedBy: 'fiscalInvoice')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $series = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(length: 3, options: ['default' => 'RON'])]
    private string $currency = 'RON';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    /**
     * Tax point (chargeable event) date per Cod Fiscal art. 319. Equals the
     * issue date for subscriptions paid in advance, but may precede it for a
     * post-fact CASE_EXTRA charge, in which case both dates must appear on the
     * document. Set at issuance (F2).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $taxPointDate = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(length: 20, enumType: FiscalInvoiceKind::class)]
    private FiscalInvoiceKind $kind = FiscalInvoiceKind::INVOICE;

    #[ORM\Column(length: 20, enumType: FiscalInvoiceStatus::class)]
    private FiscalInvoiceStatus $status = FiscalInvoiceStatus::DRAFT;

    #[ORM\Column(length: 20, enumType: EInvoiceStatus::class)]
    private EInvoiceStatus $eInvoiceStatus = EInvoiceStatus::NOT_APPLICABLE;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $providerName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerInvoiceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $spvId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $eInvoiceError = null;

    /** @var array<string, mixed> Frozen supplier snapshot (see {@see PartySnapshot}). */
    #[ORM\Column(type: Types::JSON)]
    private array $supplier = [];

    /** @var array<string, mixed> Frozen buyer snapshot (see {@see PartySnapshot}). */
    #[ORM\Column(type: Types::JSON)]
    private array $buyer = [];

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $netTotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $vatTotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $grossTotal = '0.00';

    /** @var Collection<int, FiscalInvoiceLine> */
    #[ORM\OneToMany(mappedBy: 'fiscalInvoice', targetEntity: FiscalInvoiceLine::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'stornoedBy')]
    #[ORM\JoinColumn(nullable: true)]
    private ?self $stornoOf = null;

    /** @var Collection<int, FiscalInvoice> */
    #[ORM\OneToMany(mappedBy: 'stornoOf', targetEntity: self::class)]
    private Collection $stornoedBy;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $pdfUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->stornoedBy = new ArrayCollection();
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

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

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

    public function getSeries(): ?string
    {
        return $this->series;
    }

    public function setSeries(?string $series): static
    {
        $this->series = $series;

        return $this;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(?string $number): static
    {
        $this->number = $number;

        return $this;
    }

    /** Formatted "SERIES NUMBER" once issued, null while DRAFT. */
    public function getFormattedNumber(): ?string
    {
        if (null === $this->series || null === $this->number) {
            return null;
        }

        return $this->series . ' ' . $this->number;
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

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): static
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getTaxPointDate(): ?\DateTimeImmutable
    {
        return $this->taxPointDate;
    }

    public function setTaxPointDate(?\DateTimeImmutable $taxPointDate): static
    {
        $this->taxPointDate = $taxPointDate;

        return $this;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): static
    {
        $this->dueAt = $dueAt;

        return $this;
    }

    public function getKind(): FiscalInvoiceKind
    {
        return $this->kind;
    }

    public function setKind(FiscalInvoiceKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getStatus(): FiscalInvoiceStatus
    {
        return $this->status;
    }

    public function setStatus(FiscalInvoiceStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getEInvoiceStatus(): EInvoiceStatus
    {
        return $this->eInvoiceStatus;
    }

    public function setEInvoiceStatus(EInvoiceStatus $eInvoiceStatus): static
    {
        $this->eInvoiceStatus = $eInvoiceStatus;

        return $this;
    }

    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    public function setProviderName(?string $providerName): static
    {
        $this->providerName = $providerName;

        return $this;
    }

    public function getProviderInvoiceId(): ?string
    {
        return $this->providerInvoiceId;
    }

    public function setProviderInvoiceId(?string $providerInvoiceId): static
    {
        $this->providerInvoiceId = $providerInvoiceId;

        return $this;
    }

    public function getSpvId(): ?string
    {
        return $this->spvId;
    }

    public function setSpvId(?string $spvId): static
    {
        $this->spvId = $spvId;

        return $this;
    }

    public function getEInvoiceError(): ?string
    {
        return $this->eInvoiceError;
    }

    public function setEInvoiceError(?string $eInvoiceError): static
    {
        $this->eInvoiceError = $eInvoiceError;

        return $this;
    }

    public function getSupplier(): ?PartySnapshot
    {
        return [] === $this->supplier ? null : PartySnapshot::fromArray($this->supplier);
    }

    public function setSupplier(PartySnapshot $supplier): static
    {
        $this->supplier = $supplier->toArray();

        return $this;
    }

    public function getBuyer(): ?PartySnapshot
    {
        return [] === $this->buyer ? null : PartySnapshot::fromArray($this->buyer);
    }

    public function setBuyer(PartySnapshot $buyer): static
    {
        $this->buyer = $buyer->toArray();

        return $this;
    }

    public function getNetTotal(): string
    {
        return $this->netTotal;
    }

    public function setNetTotal(string $netTotal): static
    {
        $this->netTotal = $netTotal;

        return $this;
    }

    public function getVatTotal(): string
    {
        return $this->vatTotal;
    }

    public function setVatTotal(string $vatTotal): static
    {
        $this->vatTotal = $vatTotal;

        return $this;
    }

    public function getGrossTotal(): string
    {
        return $this->grossTotal;
    }

    public function setGrossTotal(string $grossTotal): static
    {
        $this->grossTotal = $grossTotal;

        return $this;
    }

    /** @return Collection<int, FiscalInvoiceLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(FiscalInvoiceLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setFiscalInvoice($this);
        }

        return $this;
    }

    public function removeLine(FiscalInvoiceLine $line): static
    {
        $this->lines->removeElement($line);

        return $this;
    }

    public function getStornoOf(): ?self
    {
        return $this->stornoOf;
    }

    public function setStornoOf(?self $stornoOf): static
    {
        $this->stornoOf = $stornoOf;

        return $this;
    }

    /** @return Collection<int, FiscalInvoice> */
    public function getStornoedBy(): Collection
    {
        return $this->stornoedBy;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;

        return $this;
    }

    public function getPdfUrl(): ?string
    {
        return $this->pdfUrl;
    }

    public function setPdfUrl(?string $pdfUrl): static
    {
        $this->pdfUrl = $pdfUrl;

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
}
