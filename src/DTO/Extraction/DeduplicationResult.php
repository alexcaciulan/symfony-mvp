<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

use App\DTO\Wizard\ClaimItemRow;

/**
 * What collapsing repeated claim positions produced.
 */
final readonly class DeduplicationResult
{
    /**
     * @param list<ClaimItemRow> $rows every row that went in, in the same
     *        order, with the duplicates marked rather than removed
     * @param list<PrefillConflict> $conflicts what the collapse could not settle
     */
    public function __construct(
        public array $rows = [],
        public array $conflicts = [],
    ) {}

    /**
     * The rows that will be part of the claim, duplicates excluded.
     *
     * @return list<ClaimItemRow>
     */
    public function primaryRows(): array
    {
        return array_values(array_filter($this->rows, static fn (ClaimItemRow $r): bool => !$r->excluded));
    }
}
