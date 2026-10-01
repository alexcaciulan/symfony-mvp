<?php

namespace App\Entity;

use App\Entity\Concern\PartyContactInfoTrait;
use App\Repository\DebtorRepository;
use App\Service\Party\CuiNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A company the lawyer pursues, kept once per lawyer and reused across cases,
 * like {@see Creditor}. It holds identity only: what was checked about it for a
 * given case (ANAF status, the Law 85/2014 attestation) lives on the
 * {@see LegalCaseDebtor} link, so a check never carries over to another case.
 */
#[ORM\Entity(repositoryClass: DebtorRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_debtor_user_cui_key', columns: ['user_id', 'cui_key'])]
class Debtor
{
    use PartyContactInfoTrait {
        setCui as private writeCui;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Canonical CUI ({@see CuiNormalizer}), the key two spellings of one company share. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cuiKey = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $addressCounty = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $addressLocality = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $administrator = null;

    /** @var Collection<int, LegalCaseDebtor> */
    #[ORM\OneToMany(targetEntity: LegalCaseDebtor::class, mappedBy: 'debtor')]
    private Collection $legalCaseLinks;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->legalCaseLinks = new ArrayCollection();
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function setCui(?string $cui): static
    {
        $this->writeCui($cui);
        $this->cuiKey = CuiNormalizer::canonical($cui);

        return $this;
    }

    public function getCuiKey(): ?string
    {
        return $this->cuiKey;
    }

    public function getAddressCounty(): ?string
    {
        return $this->addressCounty;
    }

    public function setAddressCounty(?string $addressCounty): static
    {
        $this->addressCounty = $addressCounty;

        return $this;
    }

    public function getAddressLocality(): ?string
    {
        return $this->addressLocality;
    }

    public function setAddressLocality(?string $addressLocality): static
    {
        $this->addressLocality = $addressLocality;

        return $this;
    }

    public function getAdministrator(): ?string
    {
        return $this->administrator;
    }

    public function setAdministrator(?string $administrator): static
    {
        $this->administrator = $administrator;

        return $this;
    }

    /** @return Collection<int, LegalCaseDebtor> */
    public function getLegalCaseLinks(): Collection
    {
        return $this->legalCaseLinks;
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
        return $this->getName();
    }
}
