<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Wizard\ClaimItemRow;
use App\Entity\Document;

/**
 * Ties the payments that account and bank statements list to the claim
 * positions they name, or, when a statement carries no payment list, flags the
 * positions its free text mentions.
 */
final class StatementPaymentSettler
{
    /** Shorter invoice numbers ("12") turn up in any statement text by chance. */
    private const MIN_SUMMARIZED_REFERENCE_LENGTH = 4;

    /** Half a ban: below it two RON totals are the same sum. */
    public const AMOUNT_TOLERANCE = 0.005;

    /**
     * Settles the positions with the payments of every statement, each payment
     * counted once across statements.
     *
     * @param list<ClaimItemRow> $rows
     * @param list<list<array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int}>> $paymentsByDocument
     *
     * @return bool whether a payment matched no position
     */
    public function settle(array $rows, array $paymentsByDocument): bool
    {
        return $this->settlePayments($rows, $this->distinctPayments($paymentsByDocument));
    }

    /**
     * Ties the payments the statements list to the positions they name.
     *
     * An invoice the statement shows as paid in full (stated so, or paid at
     * least its total) leaves the claim: it stays on screen, excluded and
     * saying why, and the lawyer can bring it back. A partly paid one keeps
     * its invoiced total and records what was paid, unimputed: imputation is
     * the lawyer's (Civil Code art. 1507-1509), and the interest is computed
     * as before. A payment naming several invoices is split only when all of
     * them are positions and it pays exactly their total; otherwise it is
     * listed on each position it names, unsplit and uncounted.
     *
     * @param list<ClaimItemRow> $rows
     * @param list<array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int}> $payments
     *
     * @return bool whether a payment matched no position
     */
    private function settlePayments(array $rows, array $payments): bool
    {
        $unmatched = false;
        $settled = [];
        foreach ($payments as $payment) {
            $matched = $this->rowsNamedBy($rows, $payment);
            if ($matched === []) {
                $unmatched = true;

                continue;
            }

            // A payment naming invoices outside the file is shared with them
            // too, so no part of it can be put on the positions it does name.
            $shared = count($payment['references']) > 1;
            $splitsExactly = $shared && count($matched) === count($payment['references'])
                && $payment['amount'] !== null && abs(array_sum(array_map(
                static fn (ClaimItemRow $row): float => $row->currency === $payment['currency'] ? $row->amount : INF,
                $matched,
            )) - $payment['amount']) < self::AMOUNT_TOLERANCE;

            foreach ($matched as $row) {
                $share = !$shared ? $payment['amount'] : ($splitsExactly ? $row->amount : null);
                if ($share !== null && $row->currency === $payment['currency']) {
                    $row->paidAmount = round($row->paidAmount + $share, 2);
                }
                $row->statementPayments[] = [
                    'amount' => $payment['amount'],
                    'currency' => $payment['currency'],
                    'date' => $payment['date'],
                    'fullyPaid' => $payment['fullyPaid'],
                    'shared' => $shared,
                ];
                $settled[spl_object_id($row)] = [$row, ($settled[spl_object_id($row)][1] ?? false) || $payment['fullyPaid']];
            }
        }

        foreach ($settled as [$row, $statedFullyPaid]) {
            // Same effect a deduction stated on the invoice has: the row can no
            // longer be waved through by the table-wide checkbox.
            $row->hasStatedDeduction = true;
            if ($row->excluded) {
                continue;
            }
            if ($statedFullyPaid || $row->paidAmount >= $row->amount - self::AMOUNT_TOLERANCE) {
                $row->excluded = true;
                $row->warningKeys[] = 'wizard.step3.claim_items.warning.paid_in_full_by_statement';
            } else {
                $row->warningKeys[] = 'wizard.step3.claim_items.warning.payment_in_statement';
            }
        }

        return $unmatched;
    }

    /**
     * The positions a payment names. A reference that names both a position
     * and its excluded duplicate settles the position; a payment is never
     * matched to the document that lists it.
     *
     * @param list<ClaimItemRow> $rows
     * @param array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int} $payment
     *
     * @return list<ClaimItemRow>
     */
    private function rowsNamedBy(array $rows, array $payment): array
    {
        $matched = [];
        foreach ($payment['references'] as $reference) {
            $named = array_filter($rows, fn (ClaimItemRow $row): bool => !$row->isCreditNote()
                && ($payment['sourceDocumentId'] === null || $row->sourceDocumentId !== $payment['sourceDocumentId'])
                && $this->referenceNames($reference, DocumentReferenceNormalizer::normalize($row->documentNumber)));
            $active = array_filter($named, static fn (ClaimItemRow $row): bool => !$row->excluded);
            foreach ($active !== [] ? $active : $named as $row) {
                $matched[spl_object_id($row)] = $row;
            }
        }

        return array_values($matched);
    }

    /**
     * Whether a reference read off a statement names an invoice number, both
     * normalized. A statement routinely appends the date ("20951/12.03.2025")
     * or drops the series ("20951" for "LDT 20951"); a bare number is only
     * trusted when it is long enough not to occur by chance.
     */
    private function referenceNames(string $reference, ?string $number): bool
    {
        if ($number === null) {
            return false;
        }
        if ($reference === $number || str_starts_with($reference, $number . '/')) {
            return true;
        }
        if (!str_contains($number, '/') && str_contains($reference, '/')) {
            $reference = strstr($reference, '/', true);
        }
        $shorter = strlen($reference) <= strlen($number) ? $reference : $number;
        $longer = $shorter === $reference ? $number : $reference;
        if ($shorter === $longer) {
            return true;
        }
        if (!ctype_digit($shorter) || strlen($shorter) < self::MIN_SUMMARIZED_REFERENCE_LENGTH
            || !str_ends_with($longer, $shorter)) {
            return false;
        }

        return !ctype_digit($longer[strlen($longer) - strlen($shorter) - 1]);
    }

    /**
     * The payments of every statement, each counted once. The account
     * statement and the bank statement routinely list the same payment; read
     * twice it would pay the invoice twice over. Repeats within one document
     * are kept: two equal instalments on one day are two payments.
     *
     * @param list<list<array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int}>> $byDocument
     *
     * @return list<array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int}>
     */
    private function distinctPayments(array $byDocument): array
    {
        $kept = [];
        $seen = [];
        foreach ($byDocument as $payments) {
            $counts = [];
            foreach ($payments as $payment) {
                $references = $payment['references'];
                sort($references);
                $key = implode('|', [
                    implode(',', $references),
                    $payment['amount'] !== null ? sprintf('%.2f', $payment['amount']) : '-',
                    $payment['currency'],
                    $payment['date'] ?? '-',
                    $payment['fullyPaid'] ? 'full' : '',
                ]);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if ($counts[$key] > ($seen[$key] ?? 0)) {
                    $kept[] = $payment;
                }
            }
            foreach ($counts as $key => $count) {
                $seen[$key] = max($seen[$key] ?? 0, $count);
            }
        }

        return $kept;
    }

    /**
     * The payments a statement lists, as extracted. Null when the payload
     * carries no list (written before payments were read as one, or the
     * answer left it out).
     *
     * @return ?list<array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int}>
     */
    public function paymentsOf(Document $document): ?array
    {
        $extracted = $document->getExtractedData();
        $claim = is_array($extracted) ? ($extracted['claim'] ?? null) : null;
        if (!is_array($claim) || !is_array($claim['payments'] ?? null)) {
            return null;
        }

        $documentCurrency = ExtractedValue::currency($claim['currency'] ?? null) ?? 'RON';
        $payments = [];
        foreach ($claim['payments'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $amount = is_numeric($entry['amount'] ?? null) && (float) $entry['amount'] > 0.0 ? (float) $entry['amount'] : null;
            $references = [];
            foreach (is_array($entry['invoiceReferences'] ?? null) ? $entry['invoiceReferences'] : [] as $reference) {
                $normalized = DocumentReferenceNormalizer::normalize(is_string($reference) ? $reference : null);
                if ($normalized !== null) {
                    $references[] = $normalized;
                }
            }
            $fullyPaid = ($entry['fullyPaid'] ?? null) === true && $references !== [];
            if ($amount === null && !$fullyPaid) {
                continue;
            }
            $payments[] = [
                'amount' => $amount,
                'currency' => ExtractedValue::currency($entry['currency'] ?? null) ?? $documentCurrency,
                'date' => ExtractedValue::date($entry['date'] ?? null)?->format('Y-m-d'),
                'references' => array_values(array_unique($references)),
                'fullyPaid' => $fullyPaid,
                'sourceDocumentId' => $document->getId(),
            ];
        }

        return $payments;
    }

    /**
     * Marks the positions a bank statement with no payment list says were
     * paid, in whole or in part. Only the free text is there, so nothing is
     * excluded or summed: the row is flagged and the lawyer reads the
     * statement.
     *
     * @param list<ClaimItemRow> $rows
     * @param list<?string> $statements the payment listings read off the statements
     *
     * @return bool whether the statements mention a payment no position matched
     */
    public function flagFromText(array $rows, array $statements): bool
    {
        $text = implode(' ', array_filter($statements, static fn (?string $s): bool => $s !== null && $s !== ''));
        if ($text === '' || !ClaimTextSignals::mentionsIncomingPayment($text)) {
            return false;
        }

        $haystack = (string) DocumentReferenceNormalizer::normalize($text);
        $matched = false;
        foreach ($rows as $row) {
            $reference = DocumentReferenceNormalizer::normalize($row->documentNumber);
            if ($reference === null || !str_contains($haystack, $reference)) {
                continue;
            }
            $matched = true;
            if (!in_array('wizard.step3.claim_items.warning.payment_in_statement', $row->warningKeys, true)) {
                $row->warningKeys[] = 'wizard.step3.claim_items.warning.payment_in_statement';
            }
            $row->hasStatedDeduction = true;
        }

        return !$matched;
    }
}
