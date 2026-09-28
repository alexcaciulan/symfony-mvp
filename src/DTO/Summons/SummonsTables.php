<?php

declare(strict_types=1);

namespace App\DTO\Summons;

/**
 * The tables of a payment notice, ready to render.
 *
 * `roundingNoteNeeded` is true when the rows, each rounded to the ban, do not
 * add up to the total, which is rounded once on the unrounded sum.
 */
final readonly class SummonsTables
{
    /**
     * @param list<PrincipalRow>          $principalRows
     * @param list<LegalInterestRow>      $legalInterestRows
     * @param list<ContractualPenaltyRow> $contractualPenaltyRows
     */
    public function __construct(
        public array $principalRows,
        public array $legalInterestRows,
        public array $contractualPenaltyRows,
        public bool $roundingNoteNeeded,
    ) {}
}
