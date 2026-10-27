<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\ClaimItemKind;
use App\Util\ClaimBalance;

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
    /**
     * Why the due date is what it is when a contractual payment term decided
     * it, or why it was left to the lawyer; dates as Y-m-d. A plain property
     * with a default rather than a promoted one, so a row serialized into a
     * session before it existed still reads back.
     *
     * @var ?array{key: string, days: int, anchor: string, printedDueDate: ?string, appliedDueDate: ?string}
     */
    public ?array $dueDateNote = null;

    /**
     * The payments an account or bank statement ties to this position, dates
     * as Y-m-d. `allocated` is the part of a payment covering several invoices
     * that went to this one; `shared` marks such a payment left unsplit, listed
     * on each invoice it names. Plain property for the same session reason as
     * the note above.
     *
     * @var list<array{amount: ?float, currency: string, date: ?string, fullyPaid: bool, shared: bool, allocated: ?float}>
     */
    public array $statementPayments = [];

    /** What an account statement shows still unpaid on this invoice, in its currency. */
    public ?float $statementBalance = null;

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
     * a payment taken off the claim, a sum the document itself says was partly
     * settled, a storno that subtracts, or an extraction the model was not sure
     * about.
     */
    public function requiresIndividualConfirmation(float $reviewThreshold): bool
    {
        return $this->needsManualFx
            || $this->paidOnInvoice() > 0.0
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

    /**
     * Signed RON value: negative for a credit note. Mirrors
     * {@see \App\Entity\ClaimItem::signedAmountRon()}: a payment tied to the
     * invoice comes off it, so the claim asks for the unpaid rest.
     */
    public function signedAmountRon(): ?float
    {
        if ($this->amountRon === null) {
            return null;
        }

        $value = abs($this->amountRon);
        if ($this->kind === ClaimItemKind::CREDIT_NOTE) {
            return -$value;
        }

        return ClaimBalance::outstandingRon($value, abs($this->amount), $this->paidOnInvoice());
    }

    /**
     * What has been paid on the invoice, in its currency. A balance the account
     * statement shows is what is left to pay whatever the invoice total turns
     * out to be, so a total the lawyer corrects keeps that balance.
     */
    public function paidOnInvoice(): float
    {
        if ($this->statementBalance !== null) {
            return max(0.0, round(abs($this->amount) - $this->statementBalance, 2));
        }

        return $this->paidAmount;
    }

    /** What is still unpaid on the invoice, in its currency. */
    public function outstandingAmount(): float
    {
        return max(0.0, round(abs($this->amount) - $this->paidOnInvoice(), 2));
    }
}
