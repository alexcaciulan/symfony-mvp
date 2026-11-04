<?php

declare(strict_types=1);

namespace App\DTO\Case;

/**
 * The positions of the claim card in display order, with the counters the
 * filters and the footer show.
 */
final readonly class ClaimPositionsView
{
    /** Above this many positions the card offers filters. */
    public const FILTER_THRESHOLD = 6;

    /** Rows shown before "show all" when the filters are on. */
    public const VISIBLE_LIMIT = 8;

    /**
     * @param list<ClaimPositionRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $countingCount,
        public int $excludedCount,
        public int $attentionCount,
        public float $paidRonTotal,
        public bool $hasForeignCurrency,
    ) {}

    public function totalCount(): int
    {
        return count($this->rows);
    }

    public function showFilters(): bool
    {
        return $this->totalCount() > self::FILTER_THRESHOLD;
    }

    public function visibleLimit(): int
    {
        return self::VISIBLE_LIMIT;
    }

    public function isPaged(): bool
    {
        return $this->showFilters() && $this->totalCount() > self::VISIBLE_LIMIT;
    }
}
