<?php

namespace App\Entity;

use App\Repository\CountyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Romanian county (județ) nomenclature entry. One of the 41 counties plus
 * the Municipality of Bucharest. `normalizedName` (diacritic-stripped,
 * lowercased) is populated by the caller for robust lookup and dedup.
 */
#[ORM\Entity(repositoryClass: CountyRepository::class)]
#[ORM\Table(name: 'county')]
#[ORM\HasLifecycleCallbacks]
class County
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    #[ORM\Column(length: 100, unique: true)]
    private string $normalizedName;

    /** @var Collection<int, City> */
    #[ORM\OneToMany(targetEntity: City::class, mappedBy: 'county')]
    private Collection $cities;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->cities = new ArrayCollection();
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

    /** @return Collection<int, City> */
    public function getCities(): Collection
    {
        return $this->cities;
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
