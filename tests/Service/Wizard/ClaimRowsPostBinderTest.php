<?php

declare(strict_types=1);

namespace App\Tests\Service\Wizard;

use App\DTO\Wizard\ClaimItemRow;
use App\Enum\ClaimItemKind;
use App\Service\Wizard\ClaimRowsPostBinder;
use PHPUnit\Framework\TestCase;

/**
 * The per-position amount a lawyer types into the step 3 table becomes the
 * principal filed with the court, so its parsing has to reject everything that
 * is not a plain positive number, keep the extracted value on a blank, and read
 * both Romanian (comma decimal, dotted thousands) and plain notation. The
 * binding of the posted table onto the rows is matched on the dedup key, and
 * what blocks the step is said by key.
 */
final class ClaimRowsPostBinderTest extends TestCase
{
    private ClaimRowsPostBinder $binder;

    protected function setUp(): void
    {
        $this->binder = new ClaimRowsPostBinder(0.6);
    }

    public function testScientificNotationIsRejected(): void
    {
        // is_numeric('1e6') is true; without a character guard this becomes a
        // million and silently corrupts the claim.
        self::assertNull($this->binder->postedAmount('1e6'));
        self::assertNull($this->binder->postedAmount('1E6'));
    }

    public function testRomanianDecimalCommaIsParsed(): void
    {
        self::assertSame(600.50, $this->binder->postedAmount('600,50'));
    }

    public function testDottedThousandsWithCommaDecimalIsParsed(): void
    {
        self::assertSame(1234.56, $this->binder->postedAmount('1.234,56'));
    }

    public function testPlainDotDecimalIsParsed(): void
    {
        self::assertSame(493.23, $this->binder->postedAmount('493.23'));
    }

    public function testBlankKeepsTheExtractedValue(): void
    {
        self::assertNull($this->binder->postedAmount(''));
        self::assertNull($this->binder->postedAmount('   '));
    }

    public function testZeroAndNegativeAreRejected(): void
    {
        self::assertNull($this->binder->postedAmount('0'));
        self::assertNull($this->binder->postedAmount('-100'));
    }

    public function testLettersAndSymbolsAreRejected(): void
    {
        self::assertNull($this->binder->postedAmount('abc'));
        self::assertNull($this->binder->postedAmount('100 RON'));
        self::assertNull($this->binder->postedAmount('12,34,56x'));
    }

    public function testNonStringIsRejected(): void
    {
        self::assertNull($this->binder->postedAmount(null));
        self::assertNull($this->binder->postedAmount(493.23));
        self::assertNull($this->binder->postedAmount(['x']));
    }

    public function testPostedDateReadsIsoDatesOnly(): void
    {
        self::assertSame('2025-03-15', $this->binder->postedDate('2025-03-15')?->format('Y-m-d'));
        self::assertSame('2025-03-15', $this->binder->postedDate(' 2025-03-15 ')?->format('Y-m-d'));
        self::assertNull($this->binder->postedDate('15.03.2025'));
        self::assertNull($this->binder->postedDate(''));
        self::assertNull($this->binder->postedDate(null));
    }

    public function testRowsAreMatchedOnTheirDedupKeyNotTheirPosition(): void
    {
        $first = $this->row('inv-1', 100.0);
        $second = $this->row('inv-2', 200.0);

        $this->binder->applyPostedRows([$first, $second], [
            ['key' => 'inv-2', 'excluded' => '1'],
            ['key' => 'inv-1'],
        ], true);

        self::assertFalse($first->excluded);
        self::assertTrue($first->confirmed);
        self::assertTrue($second->excluded);
    }

    public function testARowAbsentFromThePostedTableStaysUnconfirmed(): void
    {
        $shown = $this->row('inv-1', 100.0);
        $missing = $this->row('inv-2', 200.0);
        $missing->confirmed = true;

        $this->binder->applyPostedRows([$shown, $missing], [['key' => 'inv-1']], true);

        self::assertTrue($shown->confirmed);
        self::assertFalse($missing->confirmed);
    }

    public function testAnUnparsableAmountKeepsTheExtractedValue(): void
    {
        $row = $this->row('inv-1', 100.0);

        $this->binder->applyPostedRows([$row], [['key' => 'inv-1', 'amount' => 'abc']], true);

        self::assertSame(100.0, $row->amount);
        self::assertSame(100.0, $row->amountRon);
    }

    public function testACorrectedRonAmountMovesTheRonValueWithIt(): void
    {
        $row = $this->row('inv-1', 100.0);

        $this->binder->applyPostedRows([$row], [['key' => 'inv-1', 'amount' => '1.234,56']], true);

        self::assertSame(1234.56, $row->amount);
        self::assertSame(1234.56, $row->amountRon);
    }

    public function testAForeignAmountIsReconvertedOnlyWithAStoredRate(): void
    {
        $withRate = new ClaimItemRow(dedupKey: 'eur-1', amount: 100.0, currency: 'EUR', amountRon: 497.0, exchangeRate: 4.97);
        $withoutRate = new ClaimItemRow(dedupKey: 'eur-2', amount: 100.0, currency: 'EUR', amountRon: 497.0);

        $this->binder->applyPostedRows([$withRate, $withoutRate], [
            ['key' => 'eur-1', 'amount' => '200'],
            ['key' => 'eur-2', 'amount' => '200'],
        ], true);

        self::assertSame(994.0, $withRate->amountRon);
        self::assertNull($withoutRate->amountRon);
    }

    public function testDueDateAndCauseAreTakenFromThePostedRow(): void
    {
        $row = $this->row('inv-1', 100.0);

        $this->binder->applyPostedRows([$row], [['key' => 'inv-1', 'dueDate' => '2025-04-01', 'cause' => '  ctr. 12/2024 ']], true);

        self::assertSame('2025-04-01', $row->dueDate?->format('Y-m-d'));
        self::assertSame('ctr. 12/2024', $row->causeReference);

        $this->binder->applyPostedRows([$row], [['key' => 'inv-1', 'dueDate' => '', 'cause' => '']], true);

        self::assertSame('2025-04-01', $row->dueDate?->format('Y-m-d'));
        self::assertNull($row->causeReference);
    }

    public function testTheTableWideTickDoesNotConfirmARowBelowTheThreshold(): void
    {
        $sure = $this->row('inv-1', 100.0, confidence: 0.9);
        $unsure = $this->row('inv-2', 100.0, confidence: 0.4);

        $this->binder->applyPostedRows([$sure, $unsure], [['key' => 'inv-1'], ['key' => 'inv-2']], true);

        self::assertTrue($sure->confirmed);
        self::assertFalse($unsure->confirmed);

        $this->binder->applyPostedRows([$unsure], [['key' => 'inv-2', 'confirmed' => '1']], false);

        self::assertTrue($unsure->confirmed);
    }

    public function testAnIndividualTickConfirmsARowWithoutTheTableWideOne(): void
    {
        $row = $this->row('inv-1', 100.0);

        $this->binder->applyPostedRows([$row], [['key' => 'inv-1', 'confirmed' => '1']], false);

        self::assertTrue($row->confirmed);
    }

    public function testAllRowsExcludedBlocksTheStepBeforeAnythingElse(): void
    {
        $row = $this->row('inv-1', 100.0);
        $row->excluded = true;

        self::assertSame(['wizard.step3.claim_items.error.all_excluded'], $this->binder->validateRows([$row], false));
    }

    public function testAnUnconfirmedTableBlocksTheStep(): void
    {
        $row = $this->row('inv-1', 100.0);
        $row->confirmed = true;

        self::assertSame(['wizard.step3.claim_items.error.table_not_confirmed'], $this->binder->validateRows([$row], false));
    }

    public function testARiskyRowLeftUntickedBlocksTheStep(): void
    {
        $sure = $this->row('inv-1', 100.0);
        $sure->confirmed = true;
        $storno = $this->row('cn-1', 10.0, kind: ClaimItemKind::CREDIT_NOTE);

        self::assertSame(
            ['wizard.step3.claim_items.error.individual_confirmation'],
            $this->binder->validateRows([$sure, $storno], true),
        );
    }

    public function testNoCountableRowBlocksTheStep(): void
    {
        $row = new ClaimItemRow(dedupKey: 'eur-1', amount: 100.0, currency: 'EUR', needsManualFx: true, confirmed: true);

        self::assertSame(['wizard.step3.claim_items.error.no_countable_row'], $this->binder->validateRows([$row], true));
    }

    public function testAConfirmedTableWithACountingRowPasses(): void
    {
        $row = $this->row('inv-1', 100.0);
        $row->confirmed = true;

        self::assertSame([], $this->binder->validateRows([$row], true));
    }

    private function row(string $key, float $amount, float $confidence = 1.0, ClaimItemKind $kind = ClaimItemKind::INVOICE): ClaimItemRow
    {
        return new ClaimItemRow(dedupKey: $key, amount: $amount, kind: $kind, amountRon: $amount, confidence: $confidence);
    }
}
