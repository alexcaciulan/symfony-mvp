<?php

declare(strict_types=1);

namespace App\DTO\Calculation;

/**
 * Aggregate of the claim positions that count.
 *
 * `principalRon` is what is still unpaid on each position: a payment a
 * statement ties to an invoice comes off it. Taking it off the capital rather
 * than off the accessory first (Civil Code art. 1509 alin. 2) only ever lowers
 * the claim, so it is the creditor's to choose, and the paid part is not
 * claimed for its delay.
 */
final readonly class ClaimTotals
{
    /**
     * @param list<int>  $countedItemIds
     * @param list<int>  $excludedItemIds     excluded or unconfirmed by the lawyer
     * @param list<int>  $needsManualFxItemIds
     */
    public function __construct(
        public float $principalRon,
        public ?\DateTimeImmutable $earliestDueDate,
        public ?string $earliestInvoiceNumber,
        public ?\DateTimeImmutable $earliestInvoiceDate,
        public int $itemCount,
        public array $countedItemIds = [],
        public array $excludedItemIds = [],
        public array $needsManualFxItemIds = [],
        // Counts, not ids: a position that has not been flushed yet has no id,
        // and the wizard decides on exactly such positions.
        public int $countedCount = 0,
        public int $needsManualFxCount = 0,
    ) {}

    public function hasItems(): bool
    {
        return $this->itemCount > 0;
    }
}
