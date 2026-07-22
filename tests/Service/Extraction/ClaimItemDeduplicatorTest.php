<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Wizard\ClaimItemRow;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Service\Extraction\ClaimItemDeduplicator;
use PHPUnit\Framework\TestCase;

final class ClaimItemDeduplicatorTest extends TestCase
{
    private ClaimItemDeduplicator $deduplicator;

    protected function setUp(): void
    {
        $this->deduplicator = new ClaimItemDeduplicator();
    }

    public function testTwoSpellingsOfOneInvoiceNumberCollide(): void
    {
        $date = new \DateTimeImmutable('2025-03-15');

        self::assertSame(
            $this->deduplicator->dedupKey('FF 0012/2025', '11111111', $date, 100.0, 'RON'),
            $this->deduplicator->dedupKey('FF12/2025', 'RO 11111111', $date, 100.0, 'RON'),
        );
    }

    public function testTwoIssuersNumberingFromOneDoNotCollide(): void
    {
        $date = new \DateTimeImmutable('2025-03-15');

        self::assertNotSame(
            $this->deduplicator->dedupKey('1/2025', '11111111', $date, 100.0, 'RON'),
            $this->deduplicator->dedupKey('1/2025', '22222222', $date, 100.0, 'RON'),
        );
    }

    public function testTheWeakKeyIsUsedOnlyWithoutAnInvoiceNumber(): void
    {
        $date = new \DateTimeImmutable('2025-03-15');

        $weak = $this->deduplicator->dedupKey(null, null, $date, 100.0, 'RON');
        self::assertTrue($this->deduplicator->isWeakKey($weak));
        self::assertSame($weak, $this->deduplicator->dedupKey('   ', null, $date, 100.0, 'RON'));
        self::assertNotSame($weak, $this->deduplicator->dedupKey(null, null, $date, 100.0, 'EUR'));
        self::assertFalse($this->deduplicator->isWeakKey(
            $this->deduplicator->dedupKey('FF12/2025', null, $date, 100.0, 'RON'),
        ));
    }

    public function testDuplicatesAreNeverSummed(): void
    {
        // Adding them would ask the court for twice what is owed, and the
        // debtor need only produce the invoice to show it.
        $result = $this->deduplicator->deduplicate([
            $this->row('inv:x', 1200.0, sourceDocumentId: 1),
            $this->row('inv:x', 1200.0, sourceDocumentId: 2),
        ]);

        $primary = $result->primaryRows();
        self::assertCount(1, $primary);
        self::assertSame(1200.0, $primary[0]->amount);
        self::assertCount(2, $result->rows, 'the duplicate is excluded, not dropped');
        self::assertTrue($result->rows[1]->excluded);
    }

    public function testTheMoreAuthoritativeDocumentKeepsThePosition(): void
    {
        $statement = $this->row('inv:x', 1200.0, sourceDocumentId: 1, confidence: 0.99);
        $invoice = $this->row('inv:x', 1200.0, sourceDocumentId: 2, confidence: 0.80);

        $result = $this->deduplicator->deduplicate(
            [$statement, $invoice],
            [1 => DocumentType::EXTRAS_CONT, 2 => DocumentType::FACTURA],
        );

        self::assertSame([$invoice], $result->primaryRows());
    }

    public function testTheKeptPositionTakesWhatItDoesNotSayFromTheDuplicate(): void
    {
        $primary = $this->row('inv:x', 1200.0, sourceDocumentId: 1);
        $duplicate = $this->row('inv:x', 1200.0, sourceDocumentId: 2);
        $duplicate->dueDate = new \DateTimeImmutable('2025-04-30');
        $duplicate->causeReference = 'Contract 12/2024';

        $this->deduplicator->deduplicate([$primary, $duplicate]);

        self::assertSame('2025-04-30', $primary->dueDate?->format('Y-m-d'));
        self::assertSame('Contract 12/2024', $primary->causeReference);
    }

    public function testADivergentSumBlocks(): void
    {
        $primary = $this->row('inv:x', 1200.0, sourceDocumentId: 1);
        $duplicate = $this->row('inv:x', 1500.0, sourceDocumentId: 2);
        $primary->confirmed = true;

        $result = $this->deduplicator->deduplicate([$primary, $duplicate]);

        self::assertCount(1, $result->conflicts);
        self::assertSame(ConflictSeverity::ERROR, $result->conflicts[0]->severity);
        self::assertSame('amount', $result->conflicts[0]->field);
        self::assertFalse($primary->confirmed, 'a position under dispute cannot stay confirmed');
        self::assertSame(1200.0, $primary->amount, 'and it is certainly not averaged or summed');
    }

    public function testADivergentDueDateBlocks(): void
    {
        $primary = $this->row('inv:x', 1200.0, sourceDocumentId: 1);
        $primary->dueDate = new \DateTimeImmutable('2025-04-30');
        $duplicate = $this->row('inv:x', 1200.0, sourceDocumentId: 2);
        $duplicate->dueDate = new \DateTimeImmutable('2025-05-30');

        $result = $this->deduplicator->deduplicate([$primary, $duplicate]);

        self::assertCount(1, $result->conflicts);
        self::assertSame('dueDate', $result->conflicts[0]->field);
        self::assertSame(ConflictSeverity::ERROR, $result->conflicts[0]->severity);
    }

    public function testCollapsingOnTheWeakKeyAlwaysWarns(): void
    {
        // Two legitimate invoices can share a day and a sum.
        $result = $this->deduplicator->deduplicate([
            $this->row('amt:x', 1200.0, sourceDocumentId: 1),
            $this->row('amt:x', 1200.0, sourceDocumentId: 2),
        ]);

        self::assertCount(1, $result->conflicts);
        self::assertSame(ConflictSeverity::WARNING, $result->conflicts[0]->severity);
        self::assertContains(
            'wizard.step3.claim_items.warning.weak_key_collapse',
            $result->primaryRows()[0]->warningKeys,
        );
    }

    public function testTheExcludedDuplicateGetsAKeyOfItsOwn(): void
    {
        // The claim positions carry a unique constraint per case, so a repeated
        // key would surface as a failed transaction at the last step.
        $result = $this->deduplicator->deduplicate([
            $this->row('inv:x', 1200.0, sourceDocumentId: 1),
            $this->row('inv:x', 1200.0, sourceDocumentId: 2),
            $this->row('inv:x', 1200.0, sourceDocumentId: 3),
        ]);

        $keys = array_map(static fn (ClaimItemRow $r): string => $r->dedupKey, $result->rows);
        self::assertSame($keys, array_unique($keys));
    }

    public function testAPositionWithNoDuplicateIsLeftAlone(): void
    {
        $row = $this->row('inv:x', 1200.0, sourceDocumentId: 1);
        $row->confirmed = true;

        $result = $this->deduplicator->deduplicate([$row]);

        self::assertSame([], $result->conflicts);
        self::assertSame([$row], $result->rows);
        self::assertTrue($row->confirmed);
        self::assertSame([], $row->warningKeys);
    }

    private function row(string $dedupKey, float $amount, ?int $sourceDocumentId, float $confidence = 0.9): ClaimItemRow
    {
        return new ClaimItemRow(
            dedupKey: $dedupKey,
            amount: $amount,
            currency: 'RON',
            sourceDocumentId: $sourceDocumentId,
            confidence: $confidence,
        );
    }
}
