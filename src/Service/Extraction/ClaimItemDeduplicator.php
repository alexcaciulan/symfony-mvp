<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\DeduplicationResult;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Wizard\ClaimItemRow;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Enum\FieldGroup;
use App\Service\Case\DocumentReferenceNormalizer;

/**
 * Recognises the same invoice read twice and collapses it into one position.
 *
 * The same invoice reaches the wizard from several directions: the invoice
 * itself, the balance confirmation that lists it, the bank statement that
 * references it in a payment description. Each is a legitimate document and
 * each states the same debt.
 *
 * **Duplicates are never summed.** Adding them would ask the court for twice
 * what is owed, and the debtor would only have to produce the invoice to show
 * the claim is inflated. One position is kept, the others fill in what it does
 * not say, and any disagreement about the sum or the due date is reported
 * rather than averaged away.
 */
final class ClaimItemDeduplicator
{
    /**
     * Rounding tolerance when comparing two readings of one sum. Below this the
     * difference is how the two documents rounded, not a dispute about the debt.
     */
    private const AMOUNT_EPSILON = 0.005;

    public function __construct(
        private readonly FieldAuthorityMatrix $authority = new FieldAuthorityMatrix(),
    ) {}

    /**
     * The key two readings of one position share.
     *
     * Strong on the invoice number, because that is what makes an invoice
     * identifiable, qualified by the issuer so that two suppliers numbering
     * from 1 do not collide. Weak on date plus sum only when there is no
     * number at all, which is why every weak collapse carries a warning: two
     * genuine invoices to the same debtor on the same day for the same amount
     * are unusual but entirely possible.
     */
    public function dedupKey(
        ?string $documentNumber,
        ?string $issuerCui,
        ?\DateTimeImmutable $date,
        float $amount,
        string $currency,
    ): string {
        $normalized = DocumentReferenceNormalizer::normalize($documentNumber);
        if ($normalized !== null) {
            return 'inv:' . sha1($normalized . '|' . ($this->normalizedCui($issuerCui) ?? ''));
        }

        return 'amt:' . sha1(sprintf(
            '%s|%.2f|%s',
            $date?->format('Y-m-d') ?? '',
            $amount,
            strtoupper($currency),
        ));
    }

    /**
     * Whether a key was formed without an invoice number, and so rests on the
     * coincidence of a date and a sum.
     */
    public function isWeakKey(string $dedupKey): bool
    {
        return str_starts_with($dedupKey, 'amt:');
    }

    /**
     * @param list<ClaimItemRow> $rows
     * @param array<int, DocumentType> $typesByDocument source document types,
     *        so the more authoritative reading of a position is the one kept
     */
    public function deduplicate(array $rows, array $typesByDocument = []): DeduplicationResult
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            $groups[$row->dedupKey][] = $index;
        }

        $conflicts = [];
        $usedKeys = [];
        foreach ($rows as $row) {
            $usedKeys[$row->dedupKey] = true;
        }

        foreach ($groups as $key => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            usort($indexes, fn (int $a, int $b): int => $this->comparePrimacy($rows[$a], $rows[$b], $typesByDocument));
            $primary = $rows[array_shift($indexes)];

            foreach ($indexes as $index) {
                $duplicate = $rows[$index];
                foreach ($this->divergences($primary, $duplicate, (string) $key) as $conflict) {
                    $conflicts[] = $conflict;
                }
                $this->fillGaps($primary, $duplicate);

                // The duplicate stays in the list, excluded rather than dropped.
                // A position that leaves the claim without the lawyer seeing it
                // is money the creditor loses silently, and two genuinely
                // different invoices can normalise onto one key.
                $duplicate->excluded = true;
                $duplicate->confirmed = false;
                $duplicate->warningKeys[] = 'wizard.step3.claim_items.warning.duplicate';
                // The unique constraint is per case, so the excluded row needs a
                // key of its own or the submit transaction fails at the last step.
                $duplicate->dedupKey = $this->uniqueSuffixed($duplicate->dedupKey, $usedKeys);
                $usedKeys[$duplicate->dedupKey] = true;
            }

            if ($this->isWeakKey((string) $key)) {
                $primary->warningKeys[] = 'wizard.step3.claim_items.warning.weak_key_collapse';
                $conflicts[] = new PrefillConflict(
                    scope: ConflictScope::CLAIM_ITEM,
                    severity: ConflictSeverity::WARNING,
                    messageKey: 'wizard.conflict.claim_item.weak_key_collapse',
                    field: 'dedupKey',
                    entityKey: (string) $key,
                );
            }
        }

        return new DeduplicationResult(rows: array_values($rows), conflicts: array_values($conflicts));
    }

    /**
     * Which of two readings of one position is kept: the one from the more
     * authoritative kind of document, then the more confident, then the one
     * read first. A total order, so the same documents always keep the same
     * reading.
     *
     * @param array<int, DocumentType> $typesByDocument
     */
    private function comparePrimacy(ClaimItemRow $a, ClaimItemRow $b, array $typesByDocument): int
    {
        $weightA = $this->authority->weight($this->typeOf($a, $typesByDocument), FieldGroup::CLAIM_AMOUNT);
        $weightB = $this->authority->weight($this->typeOf($b, $typesByDocument), FieldGroup::CLAIM_AMOUNT);

        return $weightB <=> $weightA
            ?: ($b->confidence <=> $a->confidence
                ?: ($a->sourceDocumentId ?? PHP_INT_MAX) <=> ($b->sourceDocumentId ?? PHP_INT_MAX));
    }

    /**
     * @param array<int, DocumentType> $typesByDocument
     */
    private function typeOf(ClaimItemRow $row, array $typesByDocument): ?DocumentType
    {
        return $row->sourceDocumentId !== null ? ($typesByDocument[$row->sourceDocumentId] ?? null) : null;
    }

    /**
     * Any disagreement about what is owed or when it fell due. Both are
     * blocking: the first decides what is asked of the court, the second
     * decides the interest and whether the claim is exigible at all
     * (CPC art. 1013 para. 1).
     *
     * @return list<PrefillConflict>
     */
    private function divergences(ClaimItemRow $primary, ClaimItemRow $duplicate, string $key): array
    {
        $conflicts = [];

        if (abs($primary->amount - $duplicate->amount) > self::AMOUNT_EPSILON) {
            $conflicts[] = new PrefillConflict(
                scope: ConflictScope::CLAIM_ITEM,
                severity: ConflictSeverity::ERROR,
                messageKey: 'wizard.conflict.claim_item.amount_mismatch',
                field: 'amount',
                entityKey: $key,
                options: [
                    new ConflictOption(value: $primary->amount, documentId: $primary->sourceDocumentId, confidence: $primary->confidence),
                    new ConflictOption(value: $duplicate->amount, documentId: $duplicate->sourceDocumentId, confidence: $duplicate->confidence),
                ],
                suggestedIndex: 0,
            );
            $primary->warningKeys[] = 'wizard.step3.claim_items.warning.amount_mismatch';
            $primary->confirmed = false;
        }

        $primaryDue = $primary->dueDate?->format('Y-m-d');
        $duplicateDue = $duplicate->dueDate?->format('Y-m-d');
        if ($primaryDue !== null && $duplicateDue !== null && $primaryDue !== $duplicateDue) {
            $conflicts[] = new PrefillConflict(
                scope: ConflictScope::CLAIM_ITEM,
                severity: ConflictSeverity::ERROR,
                messageKey: 'wizard.conflict.claim_item.due_date_mismatch',
                field: 'dueDate',
                entityKey: $key,
                options: [
                    new ConflictOption(value: $primary->dueDate, documentId: $primary->sourceDocumentId, confidence: $primary->confidence),
                    new ConflictOption(value: $duplicate->dueDate, documentId: $duplicate->sourceDocumentId, confidence: $duplicate->confidence),
                ],
                suggestedIndex: 0,
            );
            $primary->warningKeys[] = 'wizard.step3.claim_items.warning.due_date_mismatch';
            $primary->confirmed = false;
        }

        return $conflicts;
    }

    /**
     * What the kept reading does not say, taken from the one being collapsed
     * into it. Sums are never touched here: filling a gap is not arithmetic.
     */
    private function fillGaps(ClaimItemRow $primary, ClaimItemRow $duplicate): void
    {
        $primary->documentNumber ??= $duplicate->documentNumber;
        $primary->documentDate ??= $duplicate->documentDate;
        $primary->dueDate ??= $duplicate->dueDate;
        $primary->causeReference ??= $duplicate->causeReference;
        $primary->causeDocumentId ??= $duplicate->causeDocumentId;
        $primary->description ??= $duplicate->description;

        // A rate the other reading could resolve rescues this position from the
        // manual-rate list, which is the difference between it counting towards
        // the claim and being left out of the totals.
        if ($primary->needsManualFx && !$duplicate->needsManualFx && $duplicate->amountRon !== null) {
            $primary->amountRon = $duplicate->amountRon;
            $primary->exchangeRate = $duplicate->exchangeRate;
            $primary->exchangeRateDate = $duplicate->exchangeRateDate;
            $primary->needsManualFx = false;
            $primary->warningKeys = array_values(array_filter(
                $primary->warningKeys,
                static fn (string $k): bool => !str_contains($k, 'fx_'),
            ));
        }
    }

    /**
     * @param array<string, bool> $used
     */
    private function uniqueSuffixed(string $key, array $used): string
    {
        $suffix = 2;
        while (isset($used[$key . '#' . $suffix])) {
            ++$suffix;
        }

        return $key . '#' . $suffix;
    }

    private function normalizedCui(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        $trimmed = ltrim($digits, '0');

        return $trimmed === '' ? $digits : $trimmed;
    }
}
