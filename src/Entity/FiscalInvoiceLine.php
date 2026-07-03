<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single line of a {@see FiscalInvoice}. Monetary values are DECIMAL strings
 * computed by {@see App\Service\Billing\VatCalculator}. STORNO lines mirror the
 * original with negative amounts.
 */
#[ORM\Entity]
class FiscalInvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FiscalInvoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private FiscalInvoice $fiscalInvoice;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3)]
    private string $quantity = '1.000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPriceNet;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $vatRate;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $lineNet;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $lineVat;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $lineGross;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFiscalInvoice(): FiscalInvoice
    {
        return $this->fiscalInvoice;
    }

    public function setFiscalInvoice(FiscalInvoice $fiscalInvoice): static
    {
        $this->fiscalInvoice = $fiscalInvoice;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPriceNet(): string
    {
        return $this->unitPriceNet;
    }

    public function setUnitPriceNet(string $unitPriceNet): static
    {
        $this->unitPriceNet = $unitPriceNet;

        return $this;
    }

    public function getVatRate(): string
    {
        return $this->vatRate;
    }

    public function setVatRate(string $vatRate): static
    {
        $this->vatRate = $vatRate;

        return $this;
    }

    public function getLineNet(): string
    {
        return $this->lineNet;
    }

    public function setLineNet(string $lineNet): static
    {
        $this->lineNet = $lineNet;

        return $this;
    }

    public function getLineVat(): string
    {
        return $this->lineVat;
    }

    public function setLineVat(string $lineVat): static
    {
        $this->lineVat = $lineVat;

        return $this;
    }

    public function getLineGross(): string
    {
        return $this->lineGross;
    }

    public function setLineGross(string $lineGross): static
    {
        $this->lineGross = $lineGross;

        return $this;
    }
}
