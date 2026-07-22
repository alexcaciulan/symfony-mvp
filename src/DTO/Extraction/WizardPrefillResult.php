<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ConflictSeverity;

/**
 * Everything the wizard can say about a case before the lawyer has typed
 * anything: the parties, the claim, the positions, what the documents disagreed
 * about, and which document each value came from.
 *
 * The provenance is not diagnostics. When a value ends up in a filing, the
 * lawyer has to be able to say which file it was read from, and a value with no
 * traceable source is one they have to re-derive by hand.
 */
final readonly class WizardPrefillResult
{
    /**
     * @param list<ClaimItemRow> $items
     * @param list<PrefillConflict> $conflicts
     * @param array<string, array<string, int>> $provenance section name to
     *        field name to document id
     */
    public function __construct(
        public Step1CreditorData $creditor,
        public Step2DebtorsData $debtors,
        public Step3ClaimData $claim,
        public array $items = [],
        public array $conflicts = [],
        public array $provenance = [],
    ) {}

    /**
     * The same result with the claim positions attached. The positions are
     * built by the claim layer, which knows about exchange rates and credit
     * notes; keeping that out of the aggregation is what stops this DTO from
     * pulling the whole claim domain into the extraction one.
     *
     * @param list<ClaimItemRow> $items
     * @param list<PrefillConflict> $conflicts appended to the ones already collected
     */
    public function withItems(array $items, array $conflicts = []): self
    {
        return new self(
            creditor: $this->creditor,
            debtors: $this->debtors,
            claim: $this->claim,
            items: $items,
            conflicts: array_values([...$this->conflicts, ...$conflicts]),
            provenance: $this->provenance,
        );
    }

    /**
     * @return list<PrefillConflict>
     */
    public function blockingConflicts(): array
    {
        return array_values(array_filter($this->conflicts, static fn (PrefillConflict $c): bool => $c->blocks()));
    }

    public function hasBlockingConflicts(): bool
    {
        return $this->blockingConflicts() !== [];
    }

    /**
     * @return list<PrefillConflict>
     */
    public function conflictsOfSeverity(ConflictSeverity $severity): array
    {
        return array_values(array_filter(
            $this->conflicts,
            static fn (PrefillConflict $c): bool => $c->severity === $severity,
        ));
    }
}
