<?php

declare(strict_types=1);

namespace App\DTO\Calculation;

/**
 * Aggregate of the claim positions that count.
 *
 * `principalRon` deliberately ignores `paidAmount`. In the absence of an
 * agreement, Civil Code art. 1507-1509 imputes payment to costs, then interest,
 * then capital; deducting a partial payment from the principal here would state
 * a calculation that contradicts the law, which the debtor can point at in
 * opposition. Unimputed payments are reported in `unimputedPaidTotal` and
 * `unimputedPaymentItemIds` so the lawyer allocates them explicitly.
 */
final readonly class ClaimTotals
{
    /**
     * @param list<int>  $countedItemIds
     * @param list<int>  $excludedItemIds     excluded or unconfirmed by the lawyer
     * @param list<int>  $needsManualFxItemIds
     * @param list<int>  $unimputedPaymentItemIds
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
        public float $unimputedPaidTotal = 0.0,
        public array $unimputedPaymentItemIds = [],
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
