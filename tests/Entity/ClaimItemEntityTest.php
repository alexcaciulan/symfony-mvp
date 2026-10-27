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

    public function testAPaymentTiedToThePositionComesOffTheClaimedAmount(): void
    {
        $item = $this->item()->setPaidAmount('400.00');

        // The invoice keeps its total; what is claimed is the unpaid rest.
        $this->assertSame('1000.00', $item->getAmount());
        $this->assertSame(600.0, $item->signedAmountRon());
    }

    public function testAPaymentInTheInvoiceCurrencyComesOffInTheSameProportionInRon(): void
    {
        $item = $this->item()->setCurrency('EUR')->setAmount('1000.00')->setAmountRon('4975.00')->setPaidAmount('250.00');

        $this->assertSame(3731.25, $item->signedAmountRon());
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
