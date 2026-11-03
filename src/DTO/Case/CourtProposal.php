<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\Entity\Court;

/**
 * What the competence rules say about the case after its positions changed.
 *
 * Never applied by the recalculator: the court on the case is the lawyer's to
 * change, so this only carries the comparison. `proposed` is the court the
 * resolver determined when it differs from `current`; it is null when the
 * resolver could not single one out, in which case `explanationKey` says why
 * and `candidates` lists the courts it hesitated between.
 */
final readonly class CourtProposal
{
    /** @param list<Court> $candidates */
    public function __construct(
        public ?Court $current,
        public ?Court $proposed,
        public ?string $explanationKey,
        public array $candidates = [],
        /** @var array<string, string> translation parameters of `explanationKey` */
        public array $explanationParams = [],
    ) {}

    /** True when the resolver found a court and it is not the one on the case. */
    public function isChange(): bool
    {
        return $this->proposed !== null;
    }

    /** True when the resolver could not determine a single court. */
    public function isUnresolved(): bool
    {
        return $this->proposed === null;
    }
}
