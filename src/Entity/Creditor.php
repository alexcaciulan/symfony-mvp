<?php

namespace App\Entity;

use App\Entity\Concern\PartyContactInfoTrait;
use App\Repository\CreditorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreditorRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_creditor_user_cui', columns: ['user_id', 'cui'])]
class Creditor
{
    use PartyContactInfoTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /**
     * Structured registered-office location, mirroring {@see Debtor}. Drives the
     * stamp-duty payment UAT: the duty goes to the local budget of the UAT where
     * the claimant has its registered office (OUG 80/2013 art. 40 alin. 1), which
     * cannot be derived from the free-text `address`.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $addressCounty = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $addressLocality = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $anafCheckedAt = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $bankName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalRepresentative = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, LegalCase> */
    #[ORM\OneToMany(targetEntity: LegalCase::class, mappedBy: 'creditor')]
    private Collection $legalCases;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->legalCases = new ArrayCollection();
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

    public function getAnafCheckedAt(): ?\DateTimeImmutable
    {
        return $this->anafCheckedAt;
    }

    public function setAnafCheckedAt(?\DateTimeImmutable $anafCheckedAt): static
    {
        $this->anafCheckedAt = $anafCheckedAt;

        return $this;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): static
    {
        $this->bankName = $bankName;

        return $this;
    }

    public function getLegalRepresentative(): ?string
    {
        return $this->legalRepresentative;
    }

    public function setLegalRepresentative(?string $legalRepresentative): static
    {
        $this->legalRepresentative = $legalRepresentative;

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

    /** @return Collection<int, LegalCase> */
    public function getLegalCases(): Collection
    {
        return $this->legalCases;
    }

    public function __toString(): string
    {
        return $this->getName();
    }
}
