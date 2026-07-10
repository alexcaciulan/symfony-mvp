<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BnrExchangeRateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Daily BNR reference exchange rate (RON per one unit of the currency), indexed
 * as a time series so a foreign-currency claim can be converted to RON at the
 * invoice emission date. Mirrors {@see InterestRateConfig}, with a per-currency
 * dimension.
 */
#[ORM\Entity(repositoryClass: BnrExchangeRateRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_currency_rate_date', columns: ['currency', 'rate_date'])]
#[ORM\HasLifecycleCallbacks]
class BnrExchangeRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $rateDate;

    /** RON per one unit of the currency (BNR multiplier already normalized in). */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private string $rate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getRateDate(): \DateTimeImmutable
    {
        return $this->rateDate;
    }

    public function setRateDate(\DateTimeImmutable $rateDate): static
    {
        $this->rateDate = $rateDate;

        return $this;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): static
    {
        $this->rate = $rate;

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
