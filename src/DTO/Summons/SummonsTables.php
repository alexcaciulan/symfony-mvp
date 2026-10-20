<?php

declare(strict_types=1);

namespace App\DTO\Summons;

use App\Enum\ProfessionalPenaltyMargin;

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

    /**
     * The margins the legal-interest rows apply, lowest first, each once. A
     * claim mixing contracts from before and after Law 72/2013 cites both
     * provisions; the notice must state the basis of every rate it prints.
     *
     * @return list<ProfessionalPenaltyMargin>
     */
    public function legalInterestMargins(): array
    {
        $margins = [];
        foreach ($this->legalInterestRows as $row) {
            if ($row->margin !== null) {
                $margins[$row->margin->value] = $row->margin;
            }
        }
        ksort($margins);

        return array_values($margins);
    }
}
