<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\ClaimItemKind;

/**
 * One row of the claim-positions table in wizard step 3, carried in the session
 * bag between steps and materialized into a {@see \App\Entity\ClaimItem} at
 * submit.
 *
 * Mutable because the lawyer's confirmation and exclusion are written back onto
 * it from the posted table.
 */
final class ClaimItemRow
{
    /** @param list<string> $warningKeys */
    public function __construct(
        public string $dedupKey,
        public float $amount,
        public string $currency = 'RON',
        public ClaimItemKind $kind = ClaimItemKind::INVOICE,
        public ?string $documentNumber = null,
        public ?\DateTimeImmutable $documentDate = null,
        public ?\DateTimeImmutable $dueDate = null,
        public ?float $amountRon = null,
        public ?float $exchangeRate = null,
        public ?\DateTimeImmutable $exchangeRateDate = null,
        public bool $needsManualFx = false,
        public float $paidAmount = 0.0,
        public ?int $sourceDocumentId = null,
        public ?string $causeReference = null,
        public ?int $causeDocumentId = null,
        public ?string $description = null,
        public float $confidence = 1.0,
        public bool $confirmed = false,
        public bool $excluded = false,
        public array $warningKeys = [],
        public bool $hasStatedDeduction = false,
    ) {}

    /**
     * A row the single table-wide checkbox may not confirm on the lawyer's
     * behalf. Where the risk is, the attention is forced: an unresolvable rate,
     * a payment nobody has imputed, a sum the document itself says was partly
     * settled, a storno that subtracts, or an extraction the model was not sure
     * about.
     */
    public function requiresIndividualConfirmation(float $reviewThreshold): bool
    {
        return $this->needsManualFx
            || $this->paidAmount > 0.0
            || $this->hasStatedDeduction
            || $this->kind === ClaimItemKind::CREDIT_NOTE
            || $this->confidence < $reviewThreshold;
    }

    /**
     * Whether this row will end up in the totals, the petition and the index.
     * Mirrors {@see \App\Entity\ClaimItem::countsTowardsClaim()} on the rows the
     * lawyer is still editing.
     */
    public function counts(): bool
    {
        return $this->confirmed
            && !$this->excluded
            && !$this->needsManualFx
            && $this->amountRon !== null;
    }

    public function isCreditNote(): bool
    {
        return $this->kind === ClaimItemKind::CREDIT_NOTE;
    }

    /**
     * Whether this row will be part of the claim once the step is submitted.
     *
     * Confirmation is deliberately not part of it: the step cannot advance while
     * a row is left unconfirmed, so for any state that can go forward this is
     * exactly {@see counts()}. It is what the table shows figures for, and a
     * lawyer cannot confirm a sum they were not shown first.
     */
    public function willCount(): bool
    {
        return !$this->excluded
            && !$this->needsManualFx
            && $this->amountRon !== null;
    }

    /** Signed RON value: negative for a credit note. */
    public function signedAmountRon(): ?float
    {
        if ($this->amountRon === null) {
            return null;
        }

        $value = abs($this->amountRon);

        return $this->kind === ClaimItemKind::CREDIT_NOTE ? -$value : $value;
    }
}
