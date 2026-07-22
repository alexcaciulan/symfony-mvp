<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

/**
 * The outcome of aggregating one section across documents.
 */
final readonly class AggregatedFields
{
    /**
     * @param array<string, mixed> $values the values that survived
     * @param list<string> $autoFilled the fields those values cover, for the
     *        auto-filled badge in the wizard
     * @param array<string, int> $provenance field name to the document it was
     *        read from, so a value in a filing can be traced to a file
     * @param list<PrefillConflict> $conflicts disagreements the aggregation
     *        refused to settle
     */
    public function __construct(
        public array $values = [],
        public array $autoFilled = [],
        public array $provenance = [],
        public array $conflicts = [],
    ) {}
}
