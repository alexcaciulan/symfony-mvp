<?php

namespace App\Entity;

use App\Enum\CourtType;
use App\Repository\CourtRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CourtRepository::class)]
class Court
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    // DB column is NOT NULL; the property is nullable so a freshly constructed
    // Court (e.g. an EasyAdmin "new" form before the county is picked) is valid
    // in memory until setCounty() runs prior to flush.
    #[ORM\ManyToOne(targetEntity: County::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?County $county = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 20, enumType: CourtType::class)]
    private CourtType $type;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: 100, nullable: true, unique: true)]
    private ?string $portalCode = null;

    /** @var Collection<int, City> */
    #[ORM\ManyToMany(targetEntity: City::class)]
    #[ORM\JoinTable(name: 'court_covered_city')]
    private Collection $coveredCities;

    /** @var Collection<int, LegalCase> */
    #[ORM\OneToMany(targetEntity: LegalCase::class, mappedBy: 'court')]
    private Collection $legalCases;

    public function __construct()
    {
        $this->coveredCities = new ArrayCollection();
        $this->legalCases = new ArrayCollection();
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getCounty(): ?County
    {
        return $this->county;
    }

    public function setCounty(County $county): static
    {
        $this->county = $county;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getType(): CourtType
    {
        return $this->type;
    }

    public function setType(CourtType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getPortalCode(): ?string
    {
        return $this->portalCode;
    }

    public function setPortalCode(?string $portalCode): static
    {
        $this->portalCode = $portalCode;

        return $this;
    }

    /** @return Collection<int, City> */
    public function getCoveredCities(): Collection
    {
        return $this->coveredCities;
    }

    public function addCoveredCity(City $city): static
    {
        if (!$this->coveredCities->contains($city)) {
            $this->coveredCities->add($city);
        }

        return $this;
    }

    public function removeCoveredCity(City $city): static
    {
        $this->coveredCities->removeElement($city);

        return $this;
    }

    public function clearCoveredCities(): static
    {
        $this->coveredCities->clear();

        return $this;
    }

    /** @return list<string> */
    public function getCoveredCityNames(): array
    {
        return array_values($this->coveredCities->map(fn(City $c) => $c->getName())->toArray());
    }

    /** @return Collection<int, LegalCase> */
    public function getLegalCases(): Collection
    {
        return $this->legalCases;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
