<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wizard step 2 — collection wrapper around Step2DebtorEntry items.
 *
 * Pas 3.0 always seeds exactly one primary debtor entry (from the prefill
 * aggregator). Pas 3.3 makes the collection live via Symfony UX
 * `Step2DebtorsLiveComponent` (add/remove actions). Max 5 entries is a
 * product cap to keep the wizard usable; CPC art. 1015 itself doesn't limit.
 */
class Step2DebtorsData
{
    /** @param list<Step2DebtorEntry> $debtors */
    public function __construct(
        #[Assert\Count(
            min: 1,
            max: 5,
            minMessage: 'wizard.step2.error.at_least_one_debtor',
            maxMessage: 'wizard.step2.error.too_many_debtors',
        )]
        #[Assert\Valid]
        public array $debtors = [],
    ) {}
}
