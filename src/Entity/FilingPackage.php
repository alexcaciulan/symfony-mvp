<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\OpisPlacement;
use App\Repository\FilingPackageRepository;
use App\Service\Filing\FilingSizePlanner;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The set of unsigned files built for one filing of the petition, with the
 * fingerprint of the case sources they were built from. Its files are not
 * Document rows on purpose: they would otherwise land in the index, the ZIP,
 * the documents tab and the extraction pipeline.
 */
#[ORM\Entity(repositoryClass: FilingPackageRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_filing_package_case_version', columns: ['legal_case_id', 'version'])]
class FilingPackage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LegalCase::class)]
    #[ORM\JoinColumn(nullable: false)]
    private LegalCase $legalCase;

    #[ORM\Column]
    private int $version;

    #[ORM\Column(length: 30, enumType: FilingPackageStatus::class)]
    private FilingPackageStatus $status = FilingPackageStatus::BUILT;

    #[ORM\Column(length: 30, enumType: OpisPlacement::class)]
    private OpisPlacement $opisPlacement;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $sourceFingerprint;

    /**
     * The components the fingerprint was computed over, kept so a later change
     * can be named to the lawyer instead of only detected.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $sourceManifest = [];

    #[ORM\Column]
    private bool $includesStampDutyProof = false;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $sizePlan = [];

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $staleReason = null;

    #[ORM\Column]
    private \DateTimeImmutable $builtAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $builtBy;

    #[ORM\Column]
    private int $totalUnsignedBytes = 0;

    #[ORM\Column(nullable: true)]
    private ?int $totalSignedBytes = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?FilingPackage $previousVersion = null;

    /** @var Collection<int, FilingPackagePart> */
    #[ORM\OneToMany(targetEntity: FilingPackagePart::class, mappedBy: 'package', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $parts;

    public function __construct(
        LegalCase $legalCase,
        int $version,
        OpisPlacement $opisPlacement,
        User $builtBy,
    ) {
        $this->legalCase = $legalCase;
        $this->version = $version;
        $this->opisPlacement = $opisPlacement;
        $this->builtBy = $builtBy;
        $this->builtAt = new \DateTimeImmutable();
        $this->parts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLegalCase(): LegalCase
    {
        return $this->legalCase;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStatus(): FilingPackageStatus
    {
        return $this->status;
    }

    public function setStatus(FilingPackageStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** A downloaded unsigned file may be on its way to the signing tool. */
    public function markDownloadedForSigning(): static
    {
        if ($this->status === FilingPackageStatus::BUILT) {
            $this->status = FilingPackageStatus::AWAITING_SIGNATURES;
        }

        return $this;
    }

    public function getOpisPlacement(): OpisPlacement
    {
        return $this->opisPlacement;
    }

    public function getSourceFingerprint(): string
    {
        return $this->sourceFingerprint;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function setSource(string $fingerprint, array $manifest): static
    {
        $this->sourceFingerprint = $fingerprint;
        $this->sourceManifest = $manifest;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSourceManifest(): array
    {
        return $this->sourceManifest;
    }

    public function includesStampDutyProof(): bool
    {
        return $this->includesStampDutyProof;
    }

    public function setIncludesStampDutyProof(bool $includesStampDutyProof): static
    {
        $this->includesStampDutyProof = $includesStampDutyProof;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSizePlan(): array
    {
        return $this->sizePlan;
    }

    /**
     * @param array<string, mixed> $sizePlan
     */
    public function setSizePlan(array $sizePlan): static
    {
        $this->sizePlan = $sizePlan;

        return $this;
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return array_values($this->sizePlan['warnings'] ?? []);
    }

    public function getEmailCount(): int
    {
        return count($this->sizePlan['emails'] ?? []);
    }

    /** @return list<string> */
    public function getSkippedDocuments(): array
    {
        return array_values($this->sizePlan['skippedDocuments'] ?? []);
    }

    public function getStaleReason(): ?string
    {
        return $this->staleReason;
    }

    public function markStale(string $reason): static
    {
        $this->status = FilingPackageStatus::STALE;
        $this->staleReason = $reason;

        return $this;
    }

    public function getBuiltAt(): \DateTimeImmutable
    {
        return $this->builtAt;
    }

    public function getBuiltBy(): User
    {
        return $this->builtBy;
    }

    public function getTotalUnsignedBytes(): int
    {
        return $this->totalUnsignedBytes;
    }

    public function getTotalSignedBytes(): ?int
    {
        return $this->totalSignedBytes;
    }

    public function getPreviousVersion(): ?FilingPackage
    {
        return $this->previousVersion;
    }

    public function setPreviousVersion(?FilingPackage $previousVersion): static
    {
        $this->previousVersion = $previousVersion;

        return $this;
    }

    /** @return Collection<int, FilingPackagePart> */
    public function getParts(): Collection
    {
        return $this->parts;
    }

    public function addPart(FilingPackagePart $part): static
    {
        if (!$this->parts->contains($part)) {
            $part->setPackage($this);
            $this->parts->add($part);
            $this->totalUnsignedBytes += $part->getUnsignedBytes();
        }

        return $this;
    }

    /** @return list<FilingPackagePart> */
    public function partsWithRole(FilingPartRole $role): array
    {
        return array_values($this->parts->filter(
            static fn (FilingPackagePart $p): bool => $p->getRole() === $role,
        )->toArray());
    }

    public function hasCompressedPart(): bool
    {
        return $this->parts->exists(static fn (int $_k, FilingPackagePart $p): bool => $p->isCompressionApplied());
    }

    public function annexVolumeCount(): int
    {
        return count($this->partsWithRole(FilingPartRole::ANNEX_BUNDLE));
    }

    /** Every file carries a signature the platform detected and matched, confirmed where needed. */
    public function allPartsSignatureAccepted(): bool
    {
        return !$this->parts->isEmpty()
            && $this->parts->forAll(static fn (int $_k, FilingPackagePart $p): bool => $p->isSignatureAccepted());
    }

    /** Index (0-based) of the email a part travels in, null when the plan does not list it. */
    public function emailIndexForPart(string $partToken): ?int
    {
        foreach ($this->sizePlan['emails'] ?? [] as $i => $email) {
            if (in_array($partToken, $email['parts'] ?? [], true)) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * Sizes after a signed upload: a signed file replaces its unsigned one in the
     * email it travels in, so the per-email totals follow the real bytes.
     */
    public function recomputeSignedTotals(): static
    {
        $signed = null;
        foreach ($this->parts as $part) {
            if ($part->getSignedBytes() !== null) {
                $signed = ($signed ?? 0) + $part->getSignedBytes();
            }
        }
        $this->totalSignedBytes = $signed;

        if (isset($this->sizePlan['emails'])) {
            $this->sizePlan['emails'] = FilingSizePlanner::emailBytes(
                $this->parts->toArray(),
                array_map(static fn (array $e): array => $e['parts'] ?? [], $this->sizePlan['emails']),
            );
        }

        return $this;
    }
}
