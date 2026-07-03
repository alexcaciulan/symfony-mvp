<?php

declare(strict_types=1);

namespace App\Service\Extraction;

/**
 * Computes a global confidence value as a function of COVERAGE, not just
 * average quality of the few fields a strategy happened to extract.
 *
 * Formula: `sum(per_field_confidences) / TOTAL_EXPECTED_FIELDS`
 *
 * The denominator is the count of fields the wizard expects to be prefilled
 * end-to-end — 10 creditor + 10 debtor + 5 claim = 25. That number must stay
 * in lockstep with:
 *   - `Step1CreditorData` (10 user-editable properties + `autoFilled` + `creditorId`)
 *   - `Step2DebtorEntry`  (10 user-editable properties + ANAF/BPI metadata + `autoFilled`)
 *   - `Step3ClaimData`    (5 user-editable properties + `autoFilled`)
 *   - the sidecard template (`_step0_sidecard_partial.html.twig`) which uses
 *     the same `{expected: 10/10/5}` triple for the per-section coverage badge.
 *
 * Cascade implication: with EXTRACTION_CONFIDENCE_THRESHOLD = 0.6, a strategy
 * needs `sum / 25 ≥ 0.6`, i.e. ≥ 15 fields at confidence 1.0 (or 25 fields at
 * confidence 0.6) to short-circuit. A PdfParser that extracts only 6 fields
 * at confidence 0.95 each gives `5.7 / 25 = 0.228` → BELOW threshold → cascade
 * continues to the AI tier, which is the correct decision: the lawyer would
 * otherwise be left to fill 14+ empty fields manually, defeating the whole
 * "AI does the work, you verify" value proposition.
 *
 * Core-field allow-list: only the fields below count toward coverage. Optional
 * secondary metadata a strategy may also extract (e.g. invoice/contract
 * identifiers, bank name, penalty clause) is deliberately EXCLUDED so adding
 * such fields never silently shifts the cascade threshold. A field must be
 * added here explicitly to participate in the coverage score.
 *
 * The debtor allow-list carries 12 fields (county + locality beyond the
 * sidecard's nominal 10), so all core fields at confidence 1.0 sum to 27 and
 * the final score clamps to 1.0 — the denominator stays at 25 by design.
 */
final class CoverageConfidenceCalculator
{
    /**
     * Count of fields the wizard step-0 sidecard reports as expected per
     * extraction. Splitting and re-summing keeps the constants matchable
     * one-to-one against the DTO property counts.
     */
    public const EXPECTED_CREDITOR_FIELDS = 10;
    public const EXPECTED_DEBTOR_FIELDS = 10;
    public const EXPECTED_CLAIM_FIELDS = 5;
    public const EXPECTED_TOTAL_FIELDS = self::EXPECTED_CREDITOR_FIELDS
        + self::EXPECTED_DEBTOR_FIELDS
        + self::EXPECTED_CLAIM_FIELDS;

    /**
     * Fields that count toward coverage, mirroring the prefill aggregator's
     * collect lists. New optional fields are intentionally absent here.
     */
    private const CORE_CREDITOR_FIELDS = [
        'personType', 'name', 'cui', 'personalId', 'onrcNumber',
        'address', 'email', 'phone', 'iban', 'legalRepresentative',
    ];
    private const CORE_DEBTOR_FIELDS = [
        'personType', 'name', 'cui', 'personalId', 'onrcNumber', 'address',
        'county', 'locality', 'email', 'phone', 'iban', 'administrator',
    ];
    private const CORE_CLAIM_FIELDS = [
        'amount', 'currency', 'dueDate', 'legalGround', 'description',
    ];

    /**
     * @param array<string, float>|null $creditorConfidence  field name → 0..1
     * @param array<string, float>|null $debtorConfidence    field name → 0..1
     * @param array<string, float>|null $claimConfidence     field name → 0..1
     *
     * @return float in [0, 1]; clamped at the top because malformed AI output
     *               (more "fields" than expected) shouldn't trip downstream
     *               threshold comparisons that assume the [0, 1] contract.
     */
    public static function compute(?array $creditorConfidence, ?array $debtorConfidence, ?array $claimConfidence): float
    {
        $sum = 0.0;
        foreach ([
            [$creditorConfidence, self::CORE_CREDITOR_FIELDS],
            [$debtorConfidence, self::CORE_DEBTOR_FIELDS],
            [$claimConfidence, self::CORE_CLAIM_FIELDS],
        ] as [$bucket, $coreFields]) {
            if ($bucket === null) {
                continue;
            }
            foreach ($coreFields as $field) {
                $value = $bucket[$field] ?? null;
                if (is_numeric($value) && $value > 0.0) {
                    $sum += (float) $value;
                }
            }
        }

        $score = $sum / self::EXPECTED_TOTAL_FIELDS;

        return $score > 1.0 ? 1.0 : $score;
    }
}
