<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use App\Repository\CourtEmailAddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An email address a court may receive filings at. Every source is unofficial:
 * the address becomes usable for filing only once a lawyer or an admin confirms
 * it, and that confirmation is their own declaration, kept with its exact text.
 */
#[ORM\Entity(repositoryClass: CourtEmailAddressRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_court_email_address_court_email', columns: ['court_id', 'email'])]
#[ORM\Index(name: 'idx_court_email_address_status', columns: ['status'])]
class CourtEmailAddress
{
    /**
     * A product prudence rule, not a legal deadline: court mailboxes change and a
     * stale confirmation must be renewed before the platform uses it again.
     */
    public const VALIDATION_TTL_DAYS = 180;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Court::class, inversedBy: 'emailAddresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Court $court;

    #[ORM\Column(length: 254)]
    private string $email;

    #[ORM\Column(length: 30, enumType: CourtEmailPurpose::class)]
    private CourtEmailPurpose $purpose;

    #[ORM\Column(length: 20, enumType: CourtEmailStatus::class)]
    private CourtEmailStatus $status = CourtEmailStatus::PROPOSED;

    #[ORM\Column(length: 20, enumType: CourtEmailSource::class)]
    private CourtEmailSource $source;

    /** The section heading the address was listed under, as the source wrote it. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sectionLabel = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $filingDefault = false;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $sourceUrl = null;

    /** The text around the address in its source, to help whoever confirms it. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $context = null;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    /** Set when the address disappears from its source; it voids any confirmation. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $missingSince = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    /** The exact statement text that was checked, not a translation key. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $validationStatement = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invalidatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $invalidReason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Court $court, string $email, CourtEmailPurpose $purpose, CourtEmailSource $source, \DateTimeImmutable $now)
    {
        $this->court = $court;
        $this->setEmail($email);
        $this->purpose = $purpose;
        $this->source = $source;
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
        $this->createdAt = $now;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function isValidationExpired(\DateTimeImmutable $now): bool
    {
        if ($this->missingSince !== null) {
            return true;
        }
        if ($this->validatedAt === null) {
            return false;
        }

        return $this->validatedAt->modify('+' . self::VALIDATION_TTL_DAYS . ' days') < $now;
    }

    public function isUsableForFiling(\DateTimeImmutable $now): bool
    {
        return $this->status === CourtEmailStatus::VALIDATED
            && $this->purpose->canReceiveFilings()
            && !$this->isValidationExpired($now);
    }

    public function isPersonal(): bool
    {
        return $this->purpose === CourtEmailPurpose::PERSONAL;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCourt(): Court
    {
        return $this->court;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = self::normalizeEmail($email);

        return $this;
    }

    public function getPurpose(): CourtEmailPurpose
    {
        return $this->purpose;
    }

    public function setPurpose(CourtEmailPurpose $purpose): static
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function getStatus(): CourtEmailStatus
    {
        return $this->status;
    }

    public function setStatus(CourtEmailStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSource(): CourtEmailSource
    {
        return $this->source;
    }

    public function getSectionLabel(): ?string
    {
        return $this->sectionLabel;
    }

    public function setSectionLabel(?string $sectionLabel): static
    {
        $this->sectionLabel = $sectionLabel;

        return $this;
    }

    public function isFilingDefault(): bool
    {
        return $this->filingDefault;
    }

    public function setFilingDefault(bool $filingDefault): static
    {
        $this->filingDefault = $filingDefault;

        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): static
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function setContext(?string $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getMissingSince(): ?\DateTimeImmutable
    {
        return $this->missingSince;
    }

    public function setMissingSince(?\DateTimeImmutable $missingSince): static
    {
        $this->missingSince = $missingSince;

        return $this;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function getValidationStatement(): ?string
    {
        return $this->validationStatement;
    }

    public function markValidated(User $by, \DateTimeImmutable $at, string $statement): static
    {
        $this->status = CourtEmailStatus::VALIDATED;
        $this->validatedBy = $by;
        $this->validatedAt = $at;
        $this->validationStatement = $statement;

        return $this;
    }

    public function clearValidation(): static
    {
        $this->validatedBy = null;
        $this->validatedAt = null;
        $this->validationStatement = null;

        return $this;
    }

    public function getInvalidatedAt(): ?\DateTimeImmutable
    {
        return $this->invalidatedAt;
    }

    public function getInvalidReason(): ?string
    {
        return $this->invalidReason;
    }

    public function markInvalid(\DateTimeImmutable $at, string $reason): static
    {
        $this->status = CourtEmailStatus::INVALID;
        $this->invalidatedAt = $at;
        $this->invalidReason = mb_substr($reason, 0, 255);
        $this->filingDefault = false;

        return $this;
    }

    public function clearInvalidation(): static
    {
        $this->invalidatedAt = null;
        $this->invalidReason = null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->isPersonal() ? '#' . $this->id : $this->email;
    }
}
