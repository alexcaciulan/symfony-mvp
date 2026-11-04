<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Wizard\ClaimItemRow;
use App\Util\RomanianAmountParser;

/**
 * Binds the step 3 positions table as the lawyer posted it onto the rows read
 * from the documents, and says what still blocks the step.
 */
final class ClaimRowsPostBinder
{
    /**
     * Fallback for `EXTRACTION_REVIEW_THRESHOLD`. Below it, a position was read
     * with too little confidence to be waved through by the table-wide
     * confirmation and needs the lawyer to tick it individually.
     */
    public const DEFAULT_REVIEW_THRESHOLD = 0.6;

    public function __construct(
        private readonly float $confidenceThreshold = self::DEFAULT_REVIEW_THRESHOLD,
    ) {}

    /** The confidence below which a row has to be ticked on its own line. */
    public function reviewThreshold(): float
    {
        return $this->confidenceThreshold;
    }

    /**
     * Romanian keyboards and Romanian invoices both use the comma as the
     * decimal mark, and thousands arrive dotted. Anything that does not read as
     * a positive number is ignored so the extracted figure survives.
     */
    public function postedAmount(mixed $raw): ?float
    {
        return is_string($raw) ? RomanianAmountParser::parse($raw) : null;
    }

    public function postedDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw));

        return $parsed !== false ? $parsed : null;
    }

    /**
     * Writes the lawyer's decisions from the posted table back onto the rows.
     *
     * Matched on the row's dedup key, never on its position in the table: the
     * rows are re-derived from the documents on POST, and an exclusion that
     * landed on a different invoice than the one it was ticked against would be
     * a decision the lawyer never made.
     *
     * The single table-wide checkbox confirms every row that carries no risk;
     * a row that does (see {@see ClaimItemRow::requiresIndividualConfirmation()})
     * only counts as confirmed when it was ticked on its own line. An excluded
     * row keeps its trace instead of disappearing.
     *
     * @param list<ClaimItemRow>   $rows
     * @param array<string, mixed> $posted
     */
    public function applyPostedRows(array $rows, array $posted, bool $tableConfirmed): void
    {
        $byKey = [];
        foreach ($posted as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = $entry['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $byKey[$key] = $entry;
            }
        }

        foreach ($rows as $row) {
            $entry = $byKey[$row->dedupKey] ?? null;
            if ($entry === null) {
                // The row was not on the table the lawyer submitted, so no
                // decision of theirs covers it. It stays unconfirmed.
                $row->confirmed = false;

                continue;
            }

            $row->excluded = (bool) ($entry['excluded'] ?? false);
            $individual = (bool) ($entry['confirmed'] ?? false);

            // A misread figure has to be correctable here, or the lawyer cannot
            // file at all. A blank or unparsable entry leaves the extracted
            // value standing rather than zeroing the position.
            $amount = $this->postedAmount($entry['amount'] ?? null);
            if ($amount !== null && abs($amount - $row->amount) > 0.001) {
                $row->amount = $amount;
                if ($row->currency === 'RON') {
                    $row->amountRon = $amount;
                } else {
                    // The stored rate belongs to the old figure; recomputing it
                    // is the factory's job, and it runs on the next render.
                    $row->amountRon = $row->exchangeRate !== null ? round($amount * $row->exchangeRate, 2) : null;
                }
            }

            $dueDate = $this->postedDate($entry['dueDate'] ?? null);
            if ($dueDate !== null) {
                $row->dueDate = $dueDate;
            }

            $cause = $entry['cause'] ?? null;
            if (is_string($cause)) {
                $trimmed = trim($cause);
                $row->causeReference = $trimmed === '' ? null : $trimmed;
            }

            $row->confirmed = $row->requiresIndividualConfirmation($this->confidenceThreshold)
                ? $individual
                : ($tableConfirmed || $individual);
        }
    }

    /**
     * @param  list<ClaimItemRow> $rows
     * @return list<string>       translation keys of what blocks the step
     */
    public function validateRows(array $rows, bool $tableConfirmed): array
    {
        $errors = [];
        $active = array_values(array_filter($rows, static fn (ClaimItemRow $row): bool => !$row->excluded));

        if ($active === []) {
            return ['wizard.step3.claim_items.error.all_excluded'];
        }

        if (!$tableConfirmed) {
            $errors[] = 'wizard.step3.claim_items.error.table_not_confirmed';
        }

        foreach ($active as $row) {
            if ($row->requiresIndividualConfirmation($this->confidenceThreshold) && !$row->confirmed) {
                $errors[] = 'wizard.step3.claim_items.error.individual_confirmation';
                break;
            }
        }

        // Every position awaiting a manual rate means nothing enters the claim,
        // and the case would silently fall back to the single-sum path on a
        // foreign-currency figure the calculators refuse. Historical EUR files
        // land here as a matter of course, so say so rather than proceed.
        if ($errors === [] && !$this->anyRowCounts($rows)) {
            $errors[] = 'wizard.step3.claim_items.error.no_countable_row';
        }

        return $errors;
    }

    /**
     * @param list<ClaimItemRow> $rows
     */
    private function anyRowCounts(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->counts()) {
                return true;
            }
        }

        return false;
    }
}
