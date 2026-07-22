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
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Extraction\ClaimItemDeduplicator;
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
        foreach ($this->loadOrdered($documentIds) as $document) {
            $type = $this->effectiveType($document);
            if ($type === DocumentType::EXTRAS_CONT) {
                $statements[] = $this->claimDescriptionOf($document);
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

        return $this->flagStatementPayments($this->deduplicator->deduplicate($rows, $typesByDocument), $statements);
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
     * Marks the positions a bank statement says were paid, in whole or in part.
     *
     * The sum claimed stays the invoiced total: imputation of a payment is the
     * lawyer's (Civil Code art. 1507-1509), and a statement line is not proof of
     * which debt it settled. What must not happen is the payment staying
     * invisible, because an invoice never mentions what was paid after it was
     * issued and the statement never becomes a position of its own.
     *
     * @param list<?string> $statements the payment listings read off the statements
     */
    private function flagStatementPayments(DeduplicationResult $result, array $statements): DeduplicationResult
    {
        $text = implode(' ', array_filter($statements, static fn (?string $s): bool => $s !== null && $s !== ''));
        if ($text === '' || !ClaimTextSignals::mentionsIncomingPayment($text)) {
            return $result;
        }

        $haystack = (string) DocumentReferenceNormalizer::normalize($text);
        $matched = false;
        foreach ($result->rows as $row) {
            $reference = DocumentReferenceNormalizer::normalize($row->documentNumber);
            if ($reference === null || !str_contains($haystack, $reference)) {
                continue;
            }
            $matched = true;
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.payment_in_statement';
            // Same effect a deduction stated on the invoice has: the row can no
            // longer be waved through by the table-wide checkbox.
            $row->hasStatedDeduction = true;
        }

        if ($matched) {
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

        $row = new ClaimItemRow(
            dedupKey: $this->dedupKey($claim->invoiceNumber, $claim->invoiceDate, $claim->amount, $claim->currency),
            amount: $claim->amount,
            currency: $claim->currency,
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

        $currency = is_string($claim['currency'] ?? null) && $claim['currency'] !== ''
            ? $claim['currency']
            : 'RON';
        $documentNumber = $this->stringOrNull($claim['invoiceNumber'] ?? null);
        $documentDate = $this->dateOrNull($claim['invoiceDate'] ?? null);
        $dueDate = $this->dateOrNull($claim['dueDate'] ?? null);
        $description = $this->stringOrNull($claim['description'] ?? null);

        // A storno is routinely printed with a positive total, and a negative
        // total is how the rest of them arrive. Either way it takes value out of
        // the claim: adding it would ask the court for twice what is owed, and
        // dropping the negative one silently would leave the claim unreduced.
        $isCreditNote = $amount < 0.0 || ClaimTextSignals::mentionsCreditNote($documentNumber, $description);
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
        if (ClaimTextSignals::mentionsDeduction($description)) {
            $row->warningKeys[] = 'wizard.step3.claim_items.warning.deduction_mentioned';
            $row->hasStatedDeduction = true;
        }

        $this->applyConversion($row);

        return $row;
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
            $item->setDocumentNumber($row->documentNumber);
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
