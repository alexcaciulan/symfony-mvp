<?php

declare(strict_types=1);

namespace App\DTO\Calculation;

/**
 * Accessories summed across the claim positions.
 *
 * `total` is rounded once, here. Rounding each position first and adding the
 * rounded figures accumulates up to half a bani per position against a sum the
 * court will re-add itself.
 *
 * A position with no due date or a zero balance cannot accrue anything; its id
 * lands in `skippedItemIds` so it is reported, not silently dropped.
 */
final readonly class AggregatedAccessoryResult
{
    /**
     * @param array<int, InterestResult> $interestByItemId
     * @param array<int, PenaltyResult>  $penaltyByItemId
     * @param list<int>                  $skippedItemIds
     */
    public function __construct(
        public float $total,
        public array $interestByItemId = [],
        public array $penaltyByItemId = [],
        public array $skippedItemIds = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->interestByItemId === [] && $this->penaltyByItemId === [];
    }

    public function forItem(int $itemId): InterestResult|PenaltyResult|null
    {
        return $this->interestByItemId[$itemId] ?? $this->penaltyByItemId[$itemId] ?? null;
    }
}
