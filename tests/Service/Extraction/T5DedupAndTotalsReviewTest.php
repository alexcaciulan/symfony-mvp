<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Wizard\ClaimItemRow;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Service\Case\ClaimTotalsService;
use App\Service\Extraction\ClaimItemDeduplicator;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial review of invoice deduplication (scenario 5) and the guarantee
 * that the new aggregation does not double the claim (scenario 7).
 *
 * The rule the debtor could otherwise exploit: never sum two readings of one
 * invoice. Adding them asks the court for twice what is owed, and the debtor
 * need only produce the invoice to show the claim is inflated.
 */
final class T5DedupAndTotalsReviewTest extends TestCase
{
    private ClaimItemDeduplicator $deduplicator;

    protected function setUp(): void
    {
        $this->deduplicator = new ClaimItemDeduplicator();
    }

    // ---------- scenario 5a: one invoice, two spellings, one position ----------

    public function testTwoSpellingsOfOneInvoiceFromOneIssuerCollapseToOnePosition(): void
    {
        $date = new \DateTimeImmutable('2025-03-15');
        $keyA = $this->deduplicator->dedupKey('FF 0012/2025', '11111111', $date, 1200.0, 'RON');
        $keyB = $this->deduplicator->dedupKey('FF12/2025', 'RO 11111111', $date, 1200.0, 'RON');
        self::assertSame($keyA, $keyB, 'the two spellings must share a key');

        $result = $this->deduplicator->deduplicate([
            $this->row($keyA, 1200.0, 1, DocumentType::FACTURA),
            $this->row($keyB, 1200.0, 2, DocumentType::FACTURA),
        ], [1 => DocumentType::FACTURA, 2 => DocumentType::FACTURA]);

        self::assertCount(1, $result->primaryRows());
    }

    // ---------- scenario 5b: the sum is never doubled ----------

    public function testThePrincipalIsNotDoubledByARepeatedInvoice(): void
    {
        $result = $this->deduplicator->deduplicate([
            $this->confirmed($this->row('inv:x', 1200.0, 1)),
            $this->confirmed($this->row('inv:x', 1200.0, 2)),
        ]);

        $primary = $result->primaryRows();
        self::assertCount(1, $primary);
        self::assertSame(1200.0, $primary[0]->amount);
        // The sum of what counts is the single invoice, not two.
        $sum = array_sum(array_map(static fn (ClaimItemRow $r): float => $r->amount, $primary));
        self::assertSame(1200.0, $sum);
    }

    public function testAReadingWithinRoundingToleranceIsNotAConflict(): void
    {
        // Two documents rounding one sum differently (1200.00 vs 1200.004) is
        // not a dispute about the debt and must not block.
        $result = $this->deduplicator->deduplicate([
            $this->row('inv:x', 1200.00, 1),
            $this->row('inv:x', 1200.004, 2),
        ]);

        self::assertSame([], $result->conflicts);
    }

    // ---------- scenario 5c: a divergent sum blocks ----------

    public function testADivergentSumOnOneKeyRaisesABlockingConflict(): void
    {
        $primary = $this->confirmed($this->row('inv:x', 1200.0, 1));
        $result = $this->deduplicator->deduplicate([
            $primary,
            $this->row('inv:x', 1500.0, 2),
        ]);

        self::assertCount(1, $result->conflicts);
        self::assertSame(ConflictSeverity::ERROR, $result->conflicts[0]->severity);
        self::assertSame('amount', $result->conflicts[0]->field);
        self::assertFalse($primary->confirmed, 'a disputed position cannot remain confirmed');
        self::assertSame(1200.0, $primary->amount, 'and it is neither averaged nor summed');
    }

    // ---------- scenario 5d: two numberless invoices, one day, one sum ----------

    public function testTwoNumberlessInvoicesOnOneDayCollapseWithAWarningButRemainRecoverable(): void
    {
        // Real dedup keys built from (no number, same date, same amount): a weak
        // key. Two genuine invoices can legitimately share a day and a sum, so
        // the collapse is a warning, and the second position stays in the list
        // excluded, ready for the lawyer to restore.
        $date = new \DateTimeImmutable('2025-03-15');
        $key = $this->deduplicator->dedupKey(null, null, $date, 500.0, 'RON');
        self::assertTrue($this->deduplicator->isWeakKey($key));

        $first = $this->row($key, 500.0, 1);
        $second = $this->row($key, 500.0, 2);
        $result = $this->deduplicator->deduplicate([$first, $second]);

        self::assertCount(2, $result->rows, 'both positions survive, one merely excluded');
        self::assertCount(1, $result->primaryRows());
        self::assertTrue($second->excluded);
        self::assertContains('wizard.step3.claim_items.warning.weak_key_collapse', $first->warningKeys);
        $warnings = array_filter($result->conflicts, static fn ($c) => $c->severity === ConflictSeverity::WARNING);
        self::assertNotSame([], $warnings, 'the weak collapse is announced');

        // The lawyer decides to keep both: un-excluding restores two counting positions.
        $second->excluded = false;
        $kept = array_filter($result->rows, static fn (ClaimItemRow $r): bool => !$r->excluded);
        self::assertCount(2, $kept);
    }

    // ---------- scenario 7: totals do not double after deduplication ----------

    public function testClaimTotalsCountTheDuplicatedInvoiceOnlyOnce(): void
    {
        // End of the chain: the deduplicated rows become entities, and the
        // totals service must see the excluded duplicate as excluded, so the
        // principal is one invoice, not two.
        $result = $this->deduplicator->deduplicate([
            $this->confirmed($this->row('inv:x', 1200.0, 1)),
            $this->confirmed($this->row('inv:x', 1200.0, 2)),
        ]);

        $items = array_map($this->materialize(...), $result->rows);
        $totals = (new ClaimTotalsService())->totals($items);

        self::assertSame(1200.0, $totals->principalRon);
        self::assertSame(1, $totals->countedCount);
    }

    public function testTwoDistinctInvoicesBothCountTowardsThePrincipal(): void
    {
        // The other direction: dedup must not swallow two genuinely different
        // invoices. Different keys, both count.
        $result = $this->deduplicator->deduplicate([
            $this->confirmed($this->row('inv:a', 1200.0, 1)),
            $this->confirmed($this->row('inv:b', 800.0, 2)),
        ]);

        $items = array_map($this->materialize(...), $result->rows);
        $totals = (new ClaimTotalsService())->totals($items);

        self::assertSame(2000.0, $totals->principalRon);
        self::assertSame(2, $totals->countedCount);
    }

    // ---------- helpers ----------

    private function row(string $dedupKey, float $amount, int $sourceDocumentId, DocumentType $type = DocumentType::FACTURA): ClaimItemRow
    {
        return new ClaimItemRow(
            dedupKey: $dedupKey,
            amount: $amount,
            currency: 'RON',
            amountRon: $amount,
            sourceDocumentId: $sourceDocumentId,
            confidence: 0.95,
        );
    }

    private function confirmed(ClaimItemRow $row): ClaimItemRow
    {
        $row->confirmed = true;

        return $row;
    }

    private function materialize(ClaimItemRow $row): ClaimItem
    {
        $item = new ClaimItem();
        $item->setLegalCase(new LegalCase());
        $item->setKind($row->kind);
        $item->setAmount(sprintf('%.2f', $row->amount));
        $item->setCurrency($row->currency);
        $item->setAmountRon($row->amountRon !== null ? sprintf('%.2f', $row->amountRon) : null);
        $item->setNeedsManualFx($row->needsManualFx);
        $item->setPaidAmount(sprintf('%.2f', $row->paidAmount));
        $item->setDedupKey($row->dedupKey);
        $item->setDueDate($row->dueDate);
        $item->setConfirmedByLawyer($row->confirmed);
        $item->setExcludedByLawyer($row->excluded);

        return $item;
    }
}
