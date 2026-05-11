<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

/**
 * Wizard step 2 — collection wrapper around Step2DebtorEntry items.
 *
 * Pas 3.0 always seeds exactly one primary debtor entry (from the prefill
 * aggregator). Pas 3.3 makes the collection live via Symfony UX
 * `Step2DebtorsLiveComponent` (add/remove actions, max 5 entries per the
 * Validator constraint added in Pas 3.1).
 */
class Step2DebtorsData
{
    /** @param list<Step2DebtorEntry> $debtors */
    public function __construct(
        public array $debtors = [],
    ) {}
}
