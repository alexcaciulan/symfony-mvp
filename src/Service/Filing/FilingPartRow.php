<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingPackagePart;

/**
 * How one file of the package reads on the signing card. Built by
 * FilingPartRowPresenter; the template only lays it out.
 */
final readonly class FilingPartRow
{
    public const STATE_MISSING = 'missing';
    public const STATE_MATCHED = 'matched';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_AWAITING = 'awaiting';
    public const STATE_REFUSED = 'refused';
    public const STATE_REFUSED_KEPT_PREVIOUS = 'refused_kept_previous';

    /**
     * @param list<array{key: string, params: array<string, string|int>, shortKey: string, strong: bool, kind: string, slug: string}> $observations
     * @param array{key: string, slug: string, params: array<string, string>, at: \DateTimeImmutable, keptPrevious: bool, overBudget: bool, rebuildable: bool, excessBytes: int}|null $refusal
     */
    public function __construct(
        public FilingPackagePart $part,
        public string $state,
        public array $observations,
        public ?array $refusal,
        public bool $panelOpen,
    ) {}

    public function observationCount(): int
    {
        return count($this->observations);
    }
}
