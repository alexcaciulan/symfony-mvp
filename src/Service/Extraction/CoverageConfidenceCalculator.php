<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\Enum\DocumentType;

/**
 * Computes a global confidence value as a function of COVERAGE, not just
 * average quality of the few fields a strategy happened to extract.
 *
 * Formula: `sum(per_field_confidences) / TOTAL_EXPECTED_FIELDS`
 *
 * The denominator (`EXTRACTION_TOTAL_EXPECTED = 25`) is the core-field count
 * that drives the CASCADE short-circuit threshold. It is deliberately decoupled
 * from the sidecard's per-section display triple (`_step0_sidecard_partial.html
 * .twig`, 11/12/12): the sidecard counts ALL prefillable fields to answer "how
 * complete is the extraction for the wizard", whereas this calculator counts
 * only CORE fields (see the allow-list below) so that adding optional metadata
 * never shifts which strategy wins the cascade. The two metrics answer different
 * questions and are intentionally allowed to diverge.
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

    /**
     * Coverage scored against what this kind of document can be expected to
     * carry.
     *
     * {@see self::compute()} measures every extraction against the full wizard
     * field set, which is the right question when one document is meant to fill
     * the whole form. It is the wrong question once documents are typed: a bank
     * statement carries no creditor identity and a handover report carries no
     * amount, so both score low no matter how well they were read, and the
     * result is treated as a poor extraction and flagged for review. The lawyer
     * then re-checks documents that had nothing more to give.
     *
     * The denominator here is the number of fields the type is expected to
     * carry, so a fully-read bank statement scores like a fully-read invoice.
     * An unknown type falls back to {@see self::compute()}: with nothing known
     * about the document, narrowing expectations would be a guess in the
     * direction that inflates the score.
     *
     * @param array<string, float>|null $creditorConfidence field name → 0..1
     * @param array<string, float>|null $debtorConfidence   field name → 0..1
     * @param array<string, float>|null $claimConfidence    field name → 0..1
     *
     * @return float in [0, 1]
     */
    public static function computeForType(
        ?DocumentType $type,
        ?array $creditorConfidence,
        ?array $debtorConfidence,
        ?array $claimConfidence,
    ): float {
        $expected = $type !== null ? self::expectedFieldsFor($type) : null;
        if ($expected === null) {
            return self::compute($creditorConfidence, $debtorConfidence, $claimConfidence);
        }

        [$creditorFields, $debtorFields, $claimFields] = $expected;
        $sum = 0.0;
        $denominator = 0;
        foreach ([
            [$creditorConfidence, $creditorFields],
            [$debtorConfidence, $debtorFields],
            [$claimConfidence, $claimFields],
        ] as [$bucket, $fields]) {
            $denominator += count($fields);
            if ($bucket === null) {
                continue;
            }
            foreach ($fields as $field) {
                $value = $bucket[$field] ?? null;
                if (is_numeric($value) && $value > 0.0) {
                    $sum += (float) $value;
                }
            }
        }

        // A type nobody expects anything from would divide by zero; treat it as
        // unknown rather than perfect.
        if ($denominator === 0) {
            return self::compute($creditorConfidence, $debtorConfidence, $claimConfidence);
        }

        $score = $sum / $denominator;

        return $score > 1.0 ? 1.0 : $score;
    }

    /**
     * Fields each document type is expected to carry, as
     * [creditor, debtor, claim]. Only the types whose expectations really
     * differ from the full set are listed; anything else keeps the full
     * expectation via the null return.
     *
     * @return array{list<string>, list<string>, list<string>}|null
     */
    private static function expectedFieldsFor(DocumentType $type): ?array
    {
        // Identity as it can be read off a document that is not a contract:
        // name and registration number, without the contact block that only
        // headed paper carries.
        $shortParty = ['name', 'cui'];

        return match ($type) {
            // Invoices head both parties and state the amount and the term,
            // which is close to the full expectation.
            DocumentType::FACTURA => [
                ['personType', 'name', 'cui', 'address', 'county', 'locality', 'iban', 'bankName'],
                ['personType', 'name', 'cui', 'address', 'county', 'locality'],
                ['amount', 'currency', 'dueDate'],
            ],
            // Contracts identify the parties fully but rarely carry a single
            // amount or a calendar due date.
            DocumentType::CONTRACT, DocumentType::ACT_ADITIONAL => [
                ['personType', 'name', 'cui', 'onrcNumber', 'address', 'county', 'locality', 'legalRepresentative'],
                ['personType', 'name', 'cui', 'onrcNumber', 'address', 'county', 'locality', 'administrator'],
                ['legalGround', 'description'],
            ],
            // A bank statement names its account holder and proves payments.
            // It legitimately says nothing about the other party.
            DocumentType::EXTRAS_CONT => [
                ['name', 'iban', 'bankName'],
                $shortParty,
                ['description'],
            ],
            // A balance confirmation is about the amount and who acknowledged it.
            DocumentType::CONFIRMARE_SOLD => [
                $shortParty,
                $shortParty,
                ['amount', 'currency', 'description'],
            ],
            // Correspondence: sender, recipient, what was demanded.
            DocumentType::SOMATIE_ANTERIOARA, DocumentType::NOTIFICARE => [
                ['name', 'cui', 'address'],
                ['name', 'cui', 'address'],
                ['amount', 'currency', 'description'],
            ],
            // Handover reports and purchase orders identify the parties and the
            // object; an amount is optional on both.
            DocumentType::PROCES_VERBAL, DocumentType::COMANDA => [
                $shortParty,
                $shortParty,
                ['description'],
            ],
            // Negotiable instruments carry the parties and the sum, nothing else.
            DocumentType::TITLU_VALOARE => [
                ['name', 'cui'],
                ['name', 'cui'],
                ['amount', 'currency', 'dueDate'],
            ],
            default => null,
        };
    }
}
