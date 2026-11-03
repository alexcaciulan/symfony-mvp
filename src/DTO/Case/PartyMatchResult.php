<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\Enum\PartyMatchStatus;

/**
 * The parties an uploaded invoice names, set against the parties of the case.
 * A certain mismatch refuses the invoice; a missing code only warns.
 */
final readonly class PartyMatchResult
{
    public function __construct(
        public PartyMatchStatus $creditor,
        public PartyMatchStatus $debtor,
        public ?string $extractedCreditorName,
        public ?string $extractedCreditorCui,
        public ?string $extractedDebtorName,
        public ?string $extractedDebtorCui,
        /** True when the names agree although a code is missing. */
        public bool $creditorNameMatches,
        public bool $debtorNameMatches,
    ) {}

    /** Both codes present on at least one side and different: the invoice belongs to other parties. */
    public function isCertainMismatch(): bool
    {
        return $this->creditor === PartyMatchStatus::MISMATCH || $this->debtor === PartyMatchStatus::MISMATCH;
    }

    /**
     * What the lawyer is told to check by eye, when the codes did not settle it.
     *
     * @return list<string> translation keys
     */
    public function warningKeys(): array
    {
        $keys = [];
        if ($this->creditor === PartyMatchStatus::UNKNOWN && !$this->creditorNameMatches) {
            $keys[] = 'case.claim.edit.warning.creditor_unverified';
        }
        if ($this->debtor === PartyMatchStatus::UNKNOWN && !$this->debtorNameMatches) {
            $keys[] = 'case.claim.edit.warning.debtor_unverified';
        }

        return $keys;
    }
}
