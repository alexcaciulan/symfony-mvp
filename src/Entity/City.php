<?php

namespace App\Entity;

use App\Enum\UatType;
use App\Repository\CityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Romanian administrative-territorial unit (UAT): municipality, town, commune
 * or Bucharest sector. Belongs to one County. `normalizedName` (diacritic-stripped,
 * lowercased) is populated by the caller for robust lookup and dedup within a county.
 */
#[ORM\Entity(repositoryClass: CityRepository::class)]
#[ORM\Table(name: 'city')]
#[ORM\UniqueConstraint(name: 'uniq_city_county_normalized', columns: ['county_id', 'normalized_name'])]
#[ORM\Index(name: 'idx_city_normalized', columns: ['normalized_name'])]
#[ORM\HasLifecycleCallbacks]
class City
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: County::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(nullable: false)]
    private County $county;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 150)]
    private string $normalizedName;

    #[ORM\Column(length: 20, enumType: UatType::class, nullable: true)]
    private ?UatType $type = null;

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

    public function getCounty(): County
    {
        return $this->county;
    }

    public function setCounty(County $county): static
    {
        $this->county = $county;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function setNormalizedName(string $normalizedName): static
    {
        $this->normalizedName = $normalizedName;

        return $this;
    }

    public function getType(): ?UatType
    {
        return $this->type;
    }

    public function setType(?UatType $type): static
    {
        $this->type = $type;

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
        return $this->name;
    }
}
