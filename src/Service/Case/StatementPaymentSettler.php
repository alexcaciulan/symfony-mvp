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
     * Settles the positions with what every statement says about them, each
     * payment counted once across statements.
     *
     * @param list<ClaimItemRow> $rows
     * @param list<list<array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int}>> $paymentsByDocument
     * @param list<list<array{reference: string, outstanding: float, currency: string, sourceDocumentId: ?int}>> $balancesByDocument
     *
     * @return bool whether a payment matched no position
     */
    public function settle(array $rows, array $paymentsByDocument, array $balancesByDocument = []): bool
    {
        $governed = $this->applyStatedBalances($rows, array_merge(...$balancesByDocument));

        return $this->settlePayments($rows, $this->distinctPayments($paymentsByDocument), $governed);
    }

    /**
     * What an account statement shows still unpaid on an invoice is the
     * creditor's own imputation of every payment received (Civil Code art.
     * 1508), so it decides the position over any payment worked out here.
     * Two statements of one invoice keep the lower balance: payments only add
     * up over time.
     *
     * @param list<ClaimItemRow> $rows
     * @param list<array{reference: string, outstanding: float, currency: string, sourceDocumentId: ?int}> $balances
     *
     * @return array<int, true> the positions a stated balance settled, by object id
     */
    private function applyStatedBalances(array $rows, array $balances): array
    {
        $governed = [];
        foreach ($balances as $balance) {
            $matched = $this->rowsNamedBy($rows, [
                'references' => [$balance['reference']],
                'sourceDocumentId' => $balance['sourceDocumentId'],
            ]);
            if (count($matched) !== 1 || $matched[0]->currency !== $balance['currency']) {
                continue;
            }

            $row = $matched[0];
            $outstanding = max(0.0, $balance['outstanding']);
            if ($row->statementBalance !== null && $row->statementBalance <= $outstanding) {
                continue;
            }
            $row->statementBalance = $outstanding;
            $row->paidAmount = round(max(0.0, $row->amount - $outstanding), 2);
            $governed[spl_object_id($row)] = true;
        }

        foreach ($rows as $row) {
            // A balance equal to the invoice says nothing was paid: no payment to flag.
            if (isset($governed[spl_object_id($row)]) && $row->paidOnInvoice() > self::AMOUNT_TOLERANCE) {
                $this->flagSettled($row, $row->statementBalance <= self::AMOUNT_TOLERANCE);
            }
        }

        return $governed;
    }

    /**
     * Ties the payments the statements list to the positions they name.
     *
     * An invoice the statement shows as paid in full (stated so, or paid at
     * least its total) leaves the claim: it stays on screen, excluded and
     * saying why, and the lawyer can bring it back. A partly paid one is
     * claimed for its unpaid rest, which accrues from the due date; the paid
     * part is not claimed for its delay. A payment naming several invoices is
     * put on them from the oldest due date on, the order the law imputes a
     * payment that names no single debt (Civil Code art. 1509 alin. 1), but
     * only when all of them are positions: a payment that also names
     * invoices outside the file may have gone to those first, so it is listed
     * on each position it names, unsplit and uncounted. A position whose
     * statement states the balance is settled by that balance alone.
     *
     * @param list<ClaimItemRow> $rows
     * @param list<array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int}> $payments
     * @param array<int, true> $governed positions a stated balance already settled
     *
     * @return bool whether a payment matched no position
     */
    private function settlePayments(array $rows, array $payments, array $governed): bool
    {
        // Earlier payments are imputed first, as they were received; undated last.
        usort($payments, static fn (array $a, array $b): int => [$a['date'] === null, $a['date']]
            <=> [$b['date'] === null, $b['date']]);

        $unmatched = false;
        $settled = [];
        foreach ($payments as $payment) {
            $matched = $this->rowsNamedBy($rows, $payment);
            if ($matched === []) {
                $unmatched = true;

                continue;
            }

            $shared = count($payment['references']) > 1;
            // A stated balance already holds this payment for the positions it
            // settles; splitting the payment again would count it twice.
            $allocates = (!$shared || count($matched) === count($payment['references']))
                && array_filter($matched, static fn (ClaimItemRow $row): bool => isset($governed[spl_object_id($row)])) === [];
            $shares = $allocates ? $this->allocate($payment, $matched) : [];

            foreach ($matched as $row) {
                $id = spl_object_id($row);
                $share = $shares[$id] ?? null;
                if ($share !== null && !isset($governed[$id])) {
                    $row->paidAmount = round($row->paidAmount + $share, 2);
                }
                $row->statementPayments[] = [
                    'amount' => $payment['amount'],
                    'currency' => $payment['currency'],
                    'date' => $payment['date'],
                    'fullyPaid' => $payment['fullyPaid'],
                    'shared' => $shared && !$allocates,
                    'allocated' => $shared && $allocates ? $share : null,
                ];
                $settled[$id] = [$row, ($settled[$id][1] ?? false) || $payment['fullyPaid']];
            }
        }

        foreach ($settled as $id => [$row, $statedFullyPaid]) {
            if (isset($governed[$id])) {
                continue;
            }
            $this->flagSettled($row, $statedFullyPaid || $row->paidAmount >= $row->amount - self::AMOUNT_TOLERANCE);
        }

        return $unmatched;
    }

    /**
     * The part of a payment each named position takes: what it still owes,
     * from the oldest due date on (then the oldest invoice), until the payment
     * runs out. A payment in another currency than a position pays none of it.
     *
     * @param array{amount: ?float, currency: string, date: ?string, references: list<string>, fullyPaid: bool, sourceDocumentId: ?int} $payment
     * @param list<ClaimItemRow> $matched
     *
     * @return array<int, float> by object id
     */
    private function allocate(array $payment, array $matched): array
    {
        if ($payment['amount'] === null) {
            return [];
        }

        usort($matched, self::oldestFirst(...));

        $left = $payment['amount'];
        $shares = [];
        foreach ($matched as $row) {
            if ($row->currency !== $payment['currency']) {
                continue;
            }
            $owed = max(0.0, $row->amount - $row->paidAmount);
            $share = round(min($left, $owed), 2);
            $shares[spl_object_id($row)] = $share;
            $left = round($left - $share, 2);
        }

        return $shares;
    }

    /**
     * Oldest due date first, then the oldest invoice, then the invoice number,
     * so invoices due the same day are paid in the order the statement keeps
     * them and the result does not depend on the upload order.
     */
    private static function oldestFirst(ClaimItemRow $a, ClaimItemRow $b): int
    {
        return [$a->dueDate === null, $a->dueDate, $a->documentDate === null, $a->documentDate, (string) $a->documentNumber]
            <=> [$b->dueDate === null, $b->dueDate, $b->documentDate === null, $b->documentDate, (string) $b->documentNumber];
    }

    /**
     * Same effect a deduction stated on the invoice has: the row can no longer
     * be waved through by the table-wide checkbox.
     */
    private function flagSettled(ClaimItemRow $row, bool $paidInFull): void
    {
        $row->hasStatedDeduction = true;
        if ($row->excluded) {
            return;
        }
        if ($paidInFull) {
            $row->excluded = true;
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.paid_in_full_by_statement';
        } elseif ($row->paidOnInvoice() > 0.0) {
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.payment_in_statement';
        } else {
            // Only a payment left unsplit reaches here: nothing came off the
            // invoice, and the lawyer has to say whether part of it is paid.
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.payment_in_statement_unsplit';
        }
    }

    /**
     * The positions a payment names. A reference that names both a position
     * and its excluded duplicate settles the position; a payment is never
     * matched to the document that lists it.
     *
     * @param list<ClaimItemRow> $rows
     * @param array{references: list<string>, sourceDocumentId: ?int} $payment
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
     * The balance an account statement shows still unpaid on each invoice it
     * lists, as extracted. Empty when the statement shows no such balance.
     *
     * @return list<array{reference: string, outstanding: float, currency: string, sourceDocumentId: ?int}>
     */
    public function balancesOf(Document $document): array
    {
        $extracted = $document->getExtractedData();
        $claim = is_array($extracted) ? ($extracted['claim'] ?? null) : null;
        if (!is_array($claim) || !is_array($claim['invoiceBalances'] ?? null)) {
            return [];
        }

        $documentCurrency = ExtractedValue::currency($claim['currency'] ?? null) ?? 'RON';
        $balances = [];
        foreach ($claim['invoiceBalances'] as $entry) {
            if (!is_array($entry) || !is_numeric($entry['outstanding'] ?? null)) {
                continue;
            }
            $reference = DocumentReferenceNormalizer::normalize(is_string($entry['invoiceReference'] ?? null) ? $entry['invoiceReference'] : null);
            if ($reference === null) {
                continue;
            }
            $balances[] = [
                'reference' => $reference,
                'outstanding' => (float) $entry['outstanding'],
                'currency' => ExtractedValue::currency($entry['currency'] ?? null) ?? $documentCurrency,
                'sourceDocumentId' => $document->getId(),
            ];
        }

        return $balances;
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
