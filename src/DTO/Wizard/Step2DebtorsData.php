<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wizard step 2 — collection wrapper around Step2DebtorEntry items.
 *
 * Seeded with one entry per party the uploaded documents describe, at least one
 * so the form always has a primary debtor card to render, and made interactive
 * by `Step2DebtorsLiveComponent` (add/remove). The cap is a product decision to
 * keep the wizard usable; CPC art. 1015 itself does not limit the number.
 */
class Step2DebtorsData
{
    /**
     * The most debtors one case may carry. Lives here rather than on the live
     * component because the constraint below and the prefill both need it, and
     * a second copy is a second thing to forget.
     */
    public const MAX_DEBTORS = 5;

    /** @param list<Step2DebtorEntry> $debtors */
    public function __construct(
        #[Assert\Count(
            min: 1,
            max: self::MAX_DEBTORS,
            minMessage: 'wizard.step2.error.at_least_one_debtor',
            maxMessage: 'wizard.step2.error.too_many_debtors',
        )]
        #[Assert\Valid]
        public array $debtors = [],
    ) {}
}
