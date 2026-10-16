<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\DeduplicationResult;
use App\DTO\Extraction\DocumentClassification;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\ClaimItem;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\ClaimItemKind;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Enum\PaymentTermAnchor;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Extraction\ClaimItemDeduplicator;
use App\Util\StringCapper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the claim positions: one per document that states a debt, converted to
 * RON at the rate of its own invoice date, and materialized into entities at
 * submit.
 *
 * Conversion is per position on purpose. A file with invoices spread over two
 * years converted at one rate would misstate every one of them; the BNR rate
 * that matters for an invoice is the one of the day it was issued.
 */
final class ClaimItemFactory
{
    /**
     * Types that state a debt of their own, so each such document contributes a
     * position. A contract states a price that may never have been invoiced and
     * a bank statement states payments, so neither produces one.
     */
    private const CLAIM_BEARING_TYPES = [
        DocumentType::FACTURA,
        DocumentType::CONFIRMARE_SOLD,
        DocumentType::TITLU_VALOARE,
    ];

    /** Shorter invoice numbers ("12") turn up in any balance text by chance. */
    private const MIN_SUMMARIZED_REFERENCE_LENGTH = 4;

    /** Types that list payments made against the invoices. */
    private const PAYMENT_LISTING_TYPES = [
        DocumentType::EXTRAS_CONT,
        DocumentType::CONFIRMARE_SOLD,
    ];

    /** Half a ban: below it two RON totals are the same sum. */
    private const AMOUNT_TOLERANCE = 0.005;

    /** Types whose payment clause binds the invoices issued under them. */
    private const CONTRACTUAL_TYPES = [
        DocumentType::CONTRACT,
        DocumentType::ACT_ADITIONAL,
    ];

    /** Shorter contract numbers ("4") turn up inside any reference by chance. */
    private const MIN_CONTAINED_CONTRACT_LENGTH = 4;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly CurrencyConverter $currencyConverter,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClaimItemDeduplicator $deduplicator = new ClaimItemDeduplicator(),
    ) {}

    /**
     * Positions read from the extraction payloads of the wizard's documents.
     * Empty when no document states a debt; the caller then falls back to
     * {@see rowFromClaim()} so a case always carries at least one position.
     *
     * @param list<int> $documentIds
     * @return list<ClaimItemRow>
     */
    public function rowsFromDocuments(array $documentIds): array
    {
        return $this->collectRows($documentIds)->rows;
    }

    /**
     * The same positions, with what the deduplication could not settle.
     *
     * The conflicts are the reason this method exists next to the one above:
     * two documents that state a different sum for one invoice is not something
     * the wizard may decide on its own, and a caller that only takes the rows
     * would drop that on the floor.
     *
     * @param list<int> $documentIds
     */
    public function collectRows(array $documentIds): DeduplicationResult
    {
        if ($documentIds === []) {
            return new DeduplicationResult();
        }

        $rows = [];
        $typesByDocument = [];
        $statements = [];
        $paymentsByDocument = [];
        $paymentTerms = [];
        foreach ($this->loadOrdered($documentIds) as $document) {
            $type = $this->effectiveType($document);
            if (in_array($type, self::PAYMENT_LISTING_TYPES, true)) {
                $listed = $this->statedPaymentsOf($document);
                if ($listed !== null) {
                    $paymentsByDocument[] = $listed;
                } elseif ($type === DocumentType::EXTRAS_CONT) {
                    // No payment list was read: only the free-text listing is
                    // there to go on.
                    $statements[] = $this->claimDescriptionOf($document);
                }
            }
            if (in_array($type, self::CONTRACTUAL_TYPES, true)) {
                $term = $this->paymentTermOf($document);
                if ($term !== null) {
                    $paymentTerms[] = $term;
                }
            }
            if (!in_array($type, self::CLAIM_BEARING_TYPES, true)) {
                continue;
            }

            $row = $this->rowFromDocument($document);
            if ($row === null) {
                continue;
            }

            $id = $document->getId();
            if ($id !== null && $type !== null) {
                $typesByDocument[$id] = $type;
            }
            $rows[] = $row;
        }

        $result = $this->settleBalanceSummaries(
            $this->settleAdvances($this->deduplicator->deduplicate($rows, $typesByDocument)),
            $typesByDocument,
        );
        $unmatched = $this->settleStatementPayments($result->rows, $this->distinctPayments($paymentsByDocument));
        $unmatched = $this->flagStatementPayments($result->rows, $statements) || $unmatched;
        $this->applyContractualPaymentTerms($result->rows, $paymentTerms, $typesByDocument);

        if (!$unmatched) {
            return $result;
        }

        // Payments nobody could tie to a position are the more dangerous case,
        // not the lesser one: the claim may be partly extinguished by a sum the
        // table does not show at all.
        return new DeduplicationResult(
            rows: $result->rows,
            conflicts: array_values([...$result->conflicts, new PrefillConflict(
                scope: ConflictScope::CLAIM_ITEM,
                severity: ConflictSeverity::WARNING,
                messageKey: 'wizard.conflict.claim_item.payment_unmatched',
                field: 'paidAmount',
            )]),
        );
    }

    /**
     * Sets the due date of each invoice from the payment term of the contract
     * it was issued under.
     *
     * Invoices are routinely issued with a due date that ignores the contract
     * (due the day they are issued, where the contract grants four days), and
     * the contract is what binds the debtor. So a term counted from the issue
     * date replaces the printed due date. A term counted from any other event,
     * or in working days, cannot be computed from the invoice: the printed date
     * stays and the row says the lawyer has to set it. Either way the row says
     * why, and the date stays editable.
     *
     * @param list<ClaimItemRow> $rows
     * @param list<array{contract: ?string, days: int, anchor: PaymentTermAnchor, businessDays: bool}> $terms
     * @param array<int, DocumentType> $typesByDocument
     */
    private function applyContractualPaymentTerms(array $rows, array $terms, array $typesByDocument): void
    {
        if ($terms === []) {
            return;
        }

        foreach ($rows as $row) {
            if ($row->isCreditNote()
                || ($typesByDocument[$row->sourceDocumentId ?? 0] ?? null) !== DocumentType::FACTURA) {
                continue;
            }

            $candidates = $this->termsGoverning($row, $terms);
            $distinct = [];
            foreach ($candidates as $term) {
                unset($term['contract']);
                $distinct[serialize($term)] = $term;
            }
            if ($distinct === []) {
                continue;
            }
            if (count($distinct) > 1) {
                // Two contractual documents disagree on the term (a contract and
                // an amendment, typically): which one applies to this invoice
                // depends on dates the wizard does not weigh.
                $row->dueDateNote = $this->dueDateNote('wizard.step3.claim_items.note.contract_term_ambiguous', null, $row);

                continue;
            }

            $term = array_values($distinct)[0];
            if ($term['anchor'] !== PaymentTermAnchor::ISSUE_DATE || $term['businessDays'] || $row->documentDate === null) {
                $row->dueDateNote = $this->dueDateNote(
                    $term['businessDays']
                        ? 'wizard.step3.claim_items.note.contract_term_manual_business_days'
                        : 'wizard.step3.claim_items.note.contract_term_manual',
                    $term,
                    $row,
                );

                continue;
            }

            $derived = $row->documentDate->modify(sprintf('+%d days', $term['days']));
            if ($row->dueDate !== null && $row->dueDate > $derived) {
                // A later date on the invoice is a longer term the creditor
                // granted, which the debtor may rely on.
                $row->dueDateNote = $this->dueDateNote('wizard.step3.claim_items.note.contract_term_longer_on_invoice', $term, $row, $row->dueDate);

                continue;
            }
            $overrides = $row->dueDate !== null && $row->dueDate->format('Y-m-d') !== $derived->format('Y-m-d');
            $row->dueDateNote = $this->dueDateNote(
                $overrides
                    ? 'wizard.step3.claim_items.note.contract_term_overrides_invoice'
                    : 'wizard.step3.claim_items.note.contract_term_applied',
                $term,
                $row,
                $derived,
            );
            $row->dueDate = $derived;
        }
    }

    /**
     * The terms that bind one invoice: those of the contract it cites, or, when
     * it cites none, those of the only contract in the file. An invoice citing a
     * contract the file does not hold gets none.
     *
     * @param list<array{contract: ?string, days: int, anchor: PaymentTermAnchor, businessDays: bool}> $terms
     * @return list<array{contract: ?string, days: int, anchor: PaymentTermAnchor, businessDays: bool}>
     */
    private function termsGoverning(ClaimItemRow $row, array $terms): array
    {
        $contracts = array_values(array_unique(array_filter(
            array_column($terms, 'contract'),
            static fn (?string $contract): bool => $contract !== null,
        )));

        $cited = DocumentReferenceNormalizer::normalize($row->causeReference);
        if ($cited === null || $contracts === []) {
            // Nothing to tell contracts apart by: the term applies only when
            // the file holds a single contract.
            return count($contracts) <= 1 ? $terms : [];
        }

        return array_values(array_filter(
            $terms,
            fn (array $term): bool => $term['contract'] !== null && $this->sameContract($term['contract'], $cited),
        ));
    }

    /**
     * "CONTRACT12/2024" on an invoice names contract "12/2024". Containment is
     * only trusted for a number long enough not to occur by chance.
     */
    private function sameContract(string $contract, string $cited): bool
    {
        if ($contract === $cited) {
            return true;
        }
        $shorter = strlen($contract) <= strlen($cited) ? $contract : $cited;
        $longer = $shorter === $contract ? $cited : $contract;

        return strlen($shorter) >= self::MIN_CONTAINED_CONTRACT_LENGTH && str_contains($longer, $shorter);
    }

    /**
     * @param ?array{days: int, anchor: PaymentTermAnchor, businessDays: bool} $term
     * @return array{key: string, days: int, anchor: string, printedDueDate: ?string, appliedDueDate: ?string}
     */
    private function dueDateNote(string $key, ?array $term, ClaimItemRow $row, ?\DateTimeImmutable $applied = null): array
    {
        return [
            'key' => $key,
            'days' => $term['days'] ?? 0,
            'anchor' => ($term['anchor'] ?? PaymentTermAnchor::OTHER)->value,
            'printedDueDate' => $row->dueDate?->format('Y-m-d'),
            'appliedDueDate' => $applied?->format('Y-m-d'),
        ];
    }

    /**
     * The payment term a contract or an amendment states, as extracted. Null
     * on payloads written before the term was extracted, and on documents that
     * state none.
     *
     * @return ?array{contract: ?string, days: int, anchor: PaymentTermAnchor, businessDays: bool}
     */
    private function paymentTermOf(Document $document): ?array
    {
        $extracted = $document->getExtractedData();
        $claim = is_array($extracted) ? ($extracted['claim'] ?? null) : null;
        if (!is_array($claim) || !is_int($claim['paymentTermDays'] ?? null) || $claim['paymentTermDays'] < 0) {
            return null;
        }

        return [
            'contract' => DocumentReferenceNormalizer::normalize($this->stringOrNull($claim['contractNumber'] ?? null)),
            'days' => $claim['paymentTermDays'],
            'anchor' => PaymentTermAnchor::tryFrom(is_string($claim['paymentTermAnchor'] ?? null) ? $claim['paymentTermAnchor'] : '')
                ?? PaymentTermAnchor::OTHER,
            'businessDays' => ($claim['paymentTermBusinessDays'] ?? null) === true,
        ];
    }

    /**
     * Writes onto the positions what the lawyer decided about the divergences
     * between two readings of the same invoice.
     *
     * The row is matched on its dedup key, which is what the conflict was raised
     * against, and never on its position in the table: the rows are rebuilt from
     * the documents on every request, and a chosen sum landing on another invoice
     * would be a decision nobody made. The RON figure is recomputed, because it
     * is what the totals, the interest and the stamp duty are read from.
     *
     * @param list<ClaimItemRow> $rows
     * @param array<string, ConflictResolution> $resolutions
     */
    public function applyResolutions(array $rows, array $resolutions): void
    {
        foreach ($resolutions as $resolution) {
            if ($resolution->scope !== ConflictScope::CLAIM_ITEM || $resolution->entityKey === null) {
                continue;
            }
            foreach ($rows as $row) {
                if ($row->dedupKey !== $resolution->entityKey) {
                    continue;
                }
                if ($resolution->field === 'amount' && is_numeric($resolution->value)) {
                    $row->amount = $row->isCreditNote() ? -abs((float) $resolution->value) : (float) $resolution->value;
                    $this->applyConversion($row);
                }
                if ($resolution->field === 'dueDate' && $resolution->value instanceof \DateTimeImmutable) {
                    $row->dueDate = $resolution->value;
                }
                // The divergence is settled, so the row stops warning about it;
                // what was in dispute and what was retained stays in the panel
                // and in the audit entry.
                $settled = match ($resolution->field) {
                    'amount' => 'wizard.step3.claim_items.warning.amount_mismatch',
                    'dueDate' => 'wizard.step3.claim_items.warning.due_date_mismatch',
                    default => null,
                };
                if ($settled !== null) {
                    $row->warningKeys = array_values(array_filter(
                        $row->warningKeys,
                        static fn (string $key): bool => $key !== $settled,
                    ));
                }
            }
        }
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
    private function settleStatementPayments(array $rows, array $payments): bool
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
    private function statedPaymentsOf(Document $document): ?array
    {
        $extracted = $document->getExtractedData();
        $claim = is_array($extracted) ? ($extracted['claim'] ?? null) : null;
        if (!is_array($claim) || !is_array($claim['payments'] ?? null)) {
            return null;
        }

        $documentCurrency = $this->currencyCode($claim['currency'] ?? null) ?? 'RON';
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
                'currency' => $this->currencyCode($entry['currency'] ?? null) ?? $documentCurrency,
                'date' => $this->dateOrNull($entry['date'] ?? null)?->format('Y-m-d'),
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
    private function flagStatementPayments(array $rows, array $statements): bool
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

    /** ISO code as positions carry it: the model may answer "ron" or "lei". */
    private function currencyCode(mixed $value): ?string
    {
        $code = $this->stringOrNull($value);
        if ($code === null) {
            return null;
        }
        $code = strtoupper(trim($code));

        return $code === 'LEI' ? 'RON' : $code;
    }

    private function claimDescriptionOf(Document $document): ?string
    {
        $extracted = $document->getExtractedData();
        $claim = is_array($extracted) ? ($extracted['claim'] ?? null) : null;

        return is_array($claim) ? $this->stringOrNull($claim['description'] ?? null) : null;
    }

    /**
     * The single position implied by a manually filled step 3. Keeps a case with
     * no usable extraction on the same code path as one with several invoices.
     */
    public function rowFromClaim(Step3ClaimData $claim): ?ClaimItemRow
    {
        if ($claim->amount === null || $claim->amount <= 0.0) {
            return null;
        }

        $currency = $this->currencyCode($claim->currency) ?? 'RON';
        $row = new ClaimItemRow(
            dedupKey: $this->dedupKey($claim->invoiceNumber, $claim->invoiceDate, $claim->amount, $currency),
            amount: $claim->amount,
            currency: $currency,
            documentNumber: $claim->invoiceNumber,
            documentDate: $claim->invoiceDate,
            dueDate: $claim->dueDate,
            causeReference: $claim->contractNumber ?? $claim->contractReference,
            description: $claim->description,
        );

        if (ClaimTextSignals::mentionsDeduction($claim->description)) {
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.deduction_mentioned';
            $row->hasStatedDeduction = true;
        }

        $this->applyConversion($row);

        return $row;
    }

    private function rowFromDocument(Document $document): ?ClaimItemRow
    {
        $extracted = $document->getExtractedData();
        $claim = is_array($extracted) ? ($extracted['claim'] ?? null) : null;
        if (!is_array($claim)) {
            return null;
        }

        $amount = is_numeric($claim['amount'] ?? null) ? (float) $claim['amount'] : null;
        if ($amount === null || $amount === 0.0) {
            return null;
        }

        $currency = $this->currencyCode($claim['currency'] ?? null) ?? 'RON';
        $documentNumber = $this->stringOrNull($claim['invoiceNumber'] ?? null);
        $documentDate = $this->dateOrNull($claim['invoiceDate'] ?? null);
        $dueDate = $this->dateOrNull($claim['dueDate'] ?? null);
        $description = $this->stringOrNull($claim['description'] ?? null);

        // A storno is routinely printed with a positive total, and a negative
        // total is how the rest of them arrive. Either way it takes value out of
        // the claim: adding it would ask the court for twice what is owed, and
        // dropping the negative one silently would leave the claim unreduced.
        // A final invoice reverses the advance on one of its lines and still
        // bills a positive balance: the word "storno" there names that line,
        // not the invoice.
        $settlesAdvance = $amount > 0.0 && ClaimTextSignals::mentionsAdvanceSettlement($description);
        $isCreditNote = $amount < 0.0
            || (!$settlesAdvance && ClaimTextSignals::mentionsCreditNote($documentNumber, $description));
        $causeReference = $this->stringOrNull($claim['contractNumber'] ?? null)
            ?? $this->stringOrNull($claim['contractReference'] ?? null);

        // The issuer qualifies the invoice number: two suppliers both numbering
        // from 1 would otherwise collide on a single position.
        $creditor = is_array($extracted['creditor'] ?? null) ? $extracted['creditor'] : [];
        $issuerCui = $this->stringOrNull($creditor['cui'] ?? null);

        $confidencePerField = is_array($claim['confidencePerField'] ?? null) ? $claim['confidencePerField'] : [];
        $amountConfidence = is_numeric($confidencePerField['amount'] ?? null)
            ? (float) $confidencePerField['amount']
            : (float) ($document->getExtractionConfidence() ?? '0');

        $row = new ClaimItemRow(
            dedupKey: $this->dedupKey($documentNumber, $documentDate, $amount, $currency, $issuerCui),
            amount: $isCreditNote ? -abs($amount) : $amount,
            currency: $currency,
            kind: $isCreditNote ? ClaimItemKind::CREDIT_NOTE : $this->kindFor($document),
            documentNumber: $documentNumber,
            documentDate: $documentDate,
            dueDate: $dueDate,
            sourceDocumentId: $document->getId(),
            causeReference: $causeReference,
            description: $description,
            confidence: $amountConfidence,
        );

        if ($isCreditNote) {
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.credit_note';
        }

        // A deduction stated on the invoice itself (advance, retention, partial
        // storno) stays in the description by design, and the description is not
        // arithmetic: the sum claimed is still the invoiced total until the
        // lawyer imputes the payment (Civil Code art. 1507-1509). Flagging the
        // row is what makes that decision theirs instead of nobody's.
        if ($settlesAdvance) {
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.final_invoice_settles_advance';
        } elseif (ClaimTextSignals::mentionsDeduction($description)) {
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.deduction_mentioned';
            $row->hasStatedDeduction = true;
        }

        $this->applyConversion($row);

        return $row;
    }

    /**
     * Takes out of the claim an advance invoice that a final invoice in the file
     * already reverses.
     *
     * The final invoice bills the whole price and takes the advance back out on
     * one line, so its total is what was left to pay once the advance was paid.
     * Claiming both asks for the advance twice when it was paid, which is how an
     * advance normally is. The advance stays on screen, excluded and saying why,
     * because an advance that was never paid is still owed and only the lawyer
     * can tell which it is.
     */
    private function settleAdvances(DeduplicationResult $result): DeduplicationResult
    {
        foreach ($result->rows as $final) {
            if ($final->isCreditNote() || !ClaimTextSignals::mentionsAdvanceSettlement($final->description)) {
                continue;
            }
            $haystack = (string) DocumentReferenceNormalizer::normalize($final->description);
            foreach ($result->rows as $advance) {
                if ($advance === $final || $advance->isCreditNote()) {
                    continue;
                }
                $reference = DocumentReferenceNormalizer::normalize($advance->documentNumber);
                // A short reference ("12") is found inside any text; only a
                // number long enough to name one invoice links the two.
                if ($reference === null || strlen($reference) < 4 || !str_contains($haystack, $reference)) {
                    continue;
                }
                $advance->excluded = true;
                $advance->warningKeys = array_values(array_filter(
                    $advance->warningKeys,
                    static fn (string $key): bool => $key !== 'wizard.step3.claim_items.warning.deduction_mentioned',
                ));
                $advance->warningKeys[] = 'wizard.step3.claim_items.warning.advance_settled_by_final';
            }
        }

        return $result;
    }

    /**
     * Takes out of the claim an account statement or balance confirmation that
     * sums up invoices already in the file.
     *
     * The creditor's ledger states one outstanding balance over several
     * invoices. Counted next to those invoices it asks for the debt twice: the
     * reviewing lawyer saw 371.712,60 lei where 169.251,60 were owed. It stays
     * on screen, excluded and saying why. When its balance is below the
     * invoices' total, the difference is payments the ledger already took into
     * account, and the row says so: imputing them stays the lawyer's call.
     *
     * @param array<int, DocumentType> $typesByDocument
     */
    private function settleBalanceSummaries(DeduplicationResult $result, array $typesByDocument): DeduplicationResult
    {
        $invoices = array_filter(
            $result->rows,
            static fn (ClaimItemRow $row): bool => !$row->excluded
                && ($typesByDocument[$row->sourceDocumentId ?? 0] ?? null) === DocumentType::FACTURA,
        );
        if ($invoices === []) {
            return $result;
        }

        foreach ($result->rows as $summary) {
            if ($summary->excluded || $summary->documentNumber !== null
                || ($typesByDocument[$summary->sourceDocumentId ?? 0] ?? null) !== DocumentType::CONFIRMARE_SOLD) {
                continue;
            }
            $mentioned = $this->mentionedReferences($summary->description);
            $covered = array_filter($invoices, static function (ClaimItemRow $invoice) use ($mentioned): bool {
                $reference = DocumentReferenceNormalizer::normalize($invoice->documentNumber);
                if ($reference === null || strlen($reference) < self::MIN_SUMMARIZED_REFERENCE_LENGTH) {
                    return false;
                }
                foreach ($mentioned as $candidate) {
                    // "23381/27.11.2025" names invoice 23381 with its date.
                    if ($candidate === $reference || str_starts_with($candidate, $reference . '/')) {
                        return true;
                    }
                }

                return false;
            });
            if ($covered === []) {
                continue;
            }

            $summary->excluded = true;
            $summary->warningKeys[] = 'wizard.step3.claim_items.warning.balance_summarizes_invoices';
            $invoicedTotal = array_sum(array_map(static fn (ClaimItemRow $r): float => $r->amountRon ?? 0.0, $covered));
            if ($summary->amountRon !== null && $summary->amountRon < $invoicedTotal - self::AMOUNT_TOLERANCE) {
                $summary->warningKeys[] = 'wizard.step3.claim_items.warning.balance_below_invoices';
            }
        }

        return $result;
    }

    /**
     * The references a text names, one word or two ("FF 0012/2025") at a time,
     * so invoice 1234 is not found inside 12345.
     *
     * @return list<string>
     */
    private function mentionedReferences(?string $text): array
    {
        $words = preg_split('/[\s,;:()]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $references = [];
        foreach ($words as $i => $word) {
            $references[] = DocumentReferenceNormalizer::normalize($word);
            if (isset($words[$i + 1])) {
                $references[] = DocumentReferenceNormalizer::normalize($word . $words[$i + 1]);
            }
        }

        return array_values(array_filter($references, static fn (?string $r): bool => $r !== null));
    }

    /**
     * Converts the position to RON at the BNR rate of its own document date.
     *
     * The converter refuses a rate further than a week from that date, which is
     * exactly right and exactly what an old foreign-currency invoice hits when
     * the historical rates were never imported. That is not a reason to break
     * the wizard: the position is flagged for a manual rate, kept out of the
     * totals, and reported.
     */
    private function applyConversion(ClaimItemRow $row): void
    {
        if ($row->currency === 'RON') {
            $row->amountRon = $row->amount;

            return;
        }

        $rateDate = $row->documentDate ?? $row->dueDate;
        if ($rateDate === null) {
            $row->needsManualFx = true;
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.fx_date_missing';

            return;
        }

        try {
            // Converted on the absolute value, sign reapplied after: a credit
            // note is a negative claim, not a negative exchange.
            $conversion = $this->currencyConverter->convertToRon(abs($row->amount), $row->currency, $rateDate);
        } catch (\RuntimeException $e) {
            $this->logger->info('claim.item.fx_unavailable', [
                'reason' => $e->getMessage(),
                'currency' => $row->currency,
                'date' => $rateDate->format('Y-m-d'),
            ]);
            $row->needsManualFx = true;
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.fx_unavailable';

            return;
        }

        $row->amountRon = $row->amount < 0.0 ? -$conversion->ronAmount : $conversion->ronAmount;
        $row->exchangeRate = $conversion->rate;
        $row->exchangeRateDate = $conversion->rateDate;
    }

    /**
     * A key that no row in $seen already holds. The claim positions carry a
     * unique constraint per case, so a repeated key would surface as a failed
     * transaction at the last step of the wizard.
     *
     * @param array<string, bool> $seen
     */
    private function uniqueSuffixed(string $key, array $seen): string
    {
        $suffix = 2;
        while (isset($seen[$key . '#' . $suffix])) {
            ++$suffix;
        }

        return $key . '#' . $suffix;
    }

    /**
     * @param list<ClaimItemRow> $rows
     * @return list<ClaimItem>
     */
    public function materialize(LegalCase $case, array $rows): array
    {
        $documentsById = $this->documentsFor($rows);

        $items = [];
        $seen = [];
        foreach ($rows as $row) {
            // Belt on the unique constraint: a session bag replayed or hand-built
            // with a repeated key must not blow up the submit transaction.
            if (isset($seen[$row->dedupKey])) {
                $row->dedupKey = $this->uniqueSuffixed($row->dedupKey, $seen);
            }
            $seen[$row->dedupKey] = true;

            $item = new ClaimItem();
            $item->setLegalCase($case);
            $item->setKind($row->kind);
            $item->setDocumentNumber(StringCapper::cap($row->documentNumber, 100));
            $item->setDocumentDate($row->documentDate);
            $item->setDueDate($row->dueDate);
            $item->setAmount(sprintf('%.2f', $row->amount));
            $item->setCurrency($row->currency);
            $item->setAmountRon($row->amountRon !== null ? sprintf('%.2f', $row->amountRon) : null);
            $item->setExchangeRate($row->exchangeRate !== null ? sprintf('%.4f', $row->exchangeRate) : null);
            $item->setExchangeRateDate($row->exchangeRateDate);
            $item->setNeedsManualFx($row->needsManualFx);
            $item->setPaidAmount(sprintf('%.2f', $row->paidAmount));
            $item->setDedupKey($row->dedupKey);
            $item->setSourceDocument(
                $row->sourceDocumentId !== null ? ($documentsById[$row->sourceDocumentId] ?? null) : null
            );
            // Not truncated: causeKey() groups positions on the whole value for
            // material competence (CPC art. 99), so a cut that made two distinct
            // causes share a prefix would route the claim to the wrong court. The
            // column has room (255); a value beyond it fails loud, not silently.
            $item->setCauseReference($row->causeReference);
            $item->setCauseDocument(
                $row->causeDocumentId !== null ? ($documentsById[$row->causeDocumentId] ?? null) : null
            );
            $item->setDescription($row->description);
            $item->setConfirmedByLawyer($row->confirmed);
            $item->setExcludedByLawyer($row->excluded);

            $case->addClaimItem($item);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The key two readings of one position share. Delegates to the
     * deduplicator, which owns the rule: there must be exactly one definition
     * of when two rows are the same invoice, or the unique constraint and the
     * collapse disagree.
     */
    public function dedupKey(
        ?string $documentNumber,
        ?\DateTimeImmutable $date,
        float $amount,
        string $currency,
        ?string $issuerCui = null,
    ): string {
        return $this->deduplicator->dedupKey($documentNumber, $issuerCui, $date, $amount, $currency);
    }

    private function kindFor(Document $document): ClaimItemKind
    {
        return match ($this->effectiveType($document)) {
            DocumentType::FACTURA => ClaimItemKind::INVOICE,
            DocumentType::TITLU_VALOARE, DocumentType::CONFIRMARE_SOLD => ClaimItemKind::OTHER,
            default => ClaimItemKind::OTHER,
        };
    }

    /**
     * What the lawyer declared, or what the extraction detected when they
     * declared nothing and the detection was confident enough to be adopted.
     */
    private function effectiveType(Document $document): ?DocumentType
    {
        $declared = $document->getDocumentType();
        if ($declared !== DocumentType::ALT_DOCUMENT) {
            return $declared;
        }

        $detected = $document->getDetectedType();
        $confidence = $document->getDetectedTypeConfidence();
        if ($detected === null || $confidence === null) {
            return null;
        }

        return (float) $confidence > DocumentClassification::ADOPTION_THRESHOLD ? $detected : null;
    }

    /**
     * @param list<int> $documentIds
     * @return list<Document>
     */
    private function loadOrdered(array $documentIds): array
    {
        /** @var list<Document> $found */
        $found = $this->documents->findBy(['id' => $documentIds], ['id' => 'ASC']);

        return $found;
    }

    /**
     * @param list<ClaimItemRow> $rows
     * @return array<int, Document>
     */
    private function documentsFor(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row->sourceDocumentId !== null) {
                $ids[$row->sourceDocumentId] = true;
            }
            if ($row->causeDocumentId !== null) {
                $ids[$row->causeDocumentId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach ($this->documents->findBy(['id' => array_keys($ids)]) as $document) {
            $id = $document->getId();
            if ($id !== null) {
                $byId[$id] = $document;
            }
        }

        return $byId;
    }

    private function stringOrNull(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    private function dateOrNull(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->setTime(0, 0, 0);
        } catch (\Exception) {
            return null;
        }
    }
}
