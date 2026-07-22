<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ClaimItem;
use PHPUnit\Framework\TestCase;

class ClaimItemEntityTest extends TestCase
{
    public function testAConfirmedRonPositionCountsTowardsTheClaim(): void
    {
        $item = $this->item();

        $this->assertTrue($item->countsTowardsClaim());
    }

    public function testAnUnconfirmedPositionDoesNotCount(): void
    {
        $item = $this->item()->setConfirmedByLawyer(false);

        $this->assertFalse($item->countsTowardsClaim());
    }

    public function testAnExcludedPositionDoesNotCount(): void
    {
        $item = $this->item()->setExcludedByLawyer(true);

        $this->assertFalse($item->countsTowardsClaim());
    }

    public function testAPositionAwaitingAManualRateDoesNotCount(): void
    {
        $item = $this->item()->setNeedsManualFx(true);

        $this->assertFalse($item->countsTowardsClaim());
    }

    public function testAPositionWithoutARonValueDoesNotCount(): void
    {
        $item = $this->item()->setAmountRon(null);

        $this->assertFalse($item->countsTowardsClaim());
    }

    public function testARecordedPaymentIsReportedAndNeverDeducted(): void
    {
        $item = $this->item()->setPaidAmount('400.00');

        $this->assertTrue($item->hasUnimputedPayment());
        // Civil Code art. 1507-1509: imputation is the lawyer's, so the position
        // still carries its full amount.
        $this->assertSame('1000.00', $item->getAmount());
        $this->assertSame('1000.00', $item->getAmountRon());
    }

    public function testCauseKeyNormalizesSpacingAndCase(): void
    {
        $a = $this->item()->setCauseReference('  Contract   nr. 12/2025 ');
        $b = $this->item()->setCauseReference('contract nr. 12/2025');

        $this->assertSame($a->causeKey(), $b->causeKey());
    }

    public function testPositionsWithNoStatedCauseShareOneGroup(): void
    {
        // The dominant case is one contract with many invoices, and cumulating
        // is what a single-position case has always done.
        $this->assertSame('', $this->item()->causeKey());
    }

    private function item(): ClaimItem
    {
        $item = new ClaimItem();
        $item->setAmount('1000.00');
        $item->setAmountRon('1000.00');
        $item->setCurrency('RON');
        $item->setDueDate(new \DateTimeImmutable('2025-01-31'));
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:test');

        return $item;
    }
}
