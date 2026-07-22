<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Service\Case\ClaimTotalsService;
use PHPUnit\Framework\TestCase;

class ClaimTotalsServiceTest extends TestCase
{
    private ClaimTotalsService $service;

    protected function setUp(): void
    {
        $this->service = new ClaimTotalsService();
    }

    public function testTotalsSumOnlyThePositionsThatCount(): void
    {
        $items = [
            $this->item('1000.00', '2025-01-31'),
            $this->item('2500.50', '2025-02-28'),
            $this->item('900.00', '2025-03-31')->setExcludedByLawyer(true),
            $this->item('700.00', '2025-04-30')->setConfirmedByLawyer(false),
        ];

        $totals = $this->service->totals($items);

        $this->assertSame(3500.50, $totals->principalRon);
        $this->assertSame(4, $totals->itemCount);
        $this->assertSame('2025-01-31', $totals->earliestDueDate?->format('Y-m-d'));
    }

    public function testEarliestDueDateAndItsInvoiceDriveTheDenormalizations(): void
    {
        $case = new LegalCase();
        $late = $this->item('1000.00', '2025-06-30', 'FF-200', '2025-06-01');
        $early = $this->item('2000.00', '2025-02-28', 'FF-100', '2025-02-01');
        $case->addClaimItem($late);
        $case->addClaimItem($early);

        $totals = $this->service->recalculate($case);

        $this->assertSame('3000.00', $case->getAmount());
        $this->assertSame('2025-02-28', $case->getDueDate()?->format('Y-m-d'));
        $this->assertSame('FF-100', $case->getInvoiceNumber());
        $this->assertSame('2025-02-01', $case->getInvoiceDate()?->format('Y-m-d'));
        $this->assertSame('FF-100', $totals->earliestInvoiceNumber);
    }

    public function testAPartialPaymentIsReportedButNeverDeductedFromThePrincipal(): void
    {
        // Civil Code art. 1507-1509 imputes payment to costs, then interest,
        // then capital; deducting it here would state a calculation the debtor
        // could show contradicts the law.
        $item = $this->item('1000.00', '2025-01-31')->setPaidAmount('250.00');

        $totals = $this->service->totals([$item]);

        $this->assertSame(1000.0, $totals->principalRon);
        $this->assertSame(250.0, $totals->unimputedPaidTotal);
    }

    public function testAPositionAwaitingAManualRateIsReportedAndLeftOut(): void
    {
        $item = $this->item('1000.00', '2025-01-31')
            ->setCurrency('EUR')
            ->setAmountRon(null)
            ->setNeedsManualFx(true);

        $totals = $this->service->totals([$item]);

        $this->assertSame(0.0, $totals->principalRon);
        $this->assertSame(1, $totals->itemCount);
    }

    public function testACaseWithoutPositionsKeepsItsScalars(): void
    {
        $case = new LegalCase();
        $case->setAmount('4321.00');
        $case->setDueDate(new \DateTime('2024-05-05'));

        $this->service->recalculate($case);

        $this->assertSame('4321.00', $case->getAmount());
        $this->assertSame('2024-05-05', $case->getDueDate()?->format('Y-m-d'));
    }

    public function testACaseWhoseEveryPositionAwaitsARateIsNotZeroedOut(): void
    {
        $case = new LegalCase();
        $case->setAmount('9999.00');
        $case->addClaimItem(
            $this->item('9999.00', '2024-05-05')->setCurrency('EUR')->setAmountRon(null)->setNeedsManualFx(true)
        );

        $this->service->recalculate($case);

        $this->assertSame('9999.00', $case->getAmount());
    }

    private function item(
        string $amount,
        string $dueDate,
        ?string $documentNumber = null,
        ?string $documentDate = null,
    ): ClaimItem {
        $item = new ClaimItem();
        $item->setAmount($amount);
        $item->setAmountRon($amount);
        $item->setCurrency('RON');
        $item->setDueDate(new \DateTimeImmutable($dueDate));
        $item->setDocumentNumber($documentNumber);
        $item->setDocumentDate($documentDate !== null ? new \DateTimeImmutable($documentDate) : null);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:' . ($documentNumber ?? $amount . $dueDate));

        return $item;
    }
}
