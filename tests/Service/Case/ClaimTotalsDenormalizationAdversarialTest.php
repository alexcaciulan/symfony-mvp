<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Service\Case\ClaimTotalsService;
use PHPUnit\Framework\TestCase;

/**
 * What the denormalized case scalars are allowed to lose.
 *
 * `ClaimTotalsService::recalculate()` is the only writer of `LegalCase::$amount`,
 * `$dueDate`, `$invoiceNumber` and `$invoiceDate`. Every one of those is read
 * later by the admissibility check, the summons and the petition, so anything it
 * overwrites with nothing is a fact about the claim that disappears.
 */
final class ClaimTotalsDenormalizationAdversarialTest extends TestCase
{
    private ClaimTotalsService $service;

    protected function setUp(): void
    {
        $this->service = new ClaimTotalsService();
    }

    /**
     * The lawyer typed a due date in step 3; the extraction produced positions
     * without one (a very common outcome, the due date is the field models miss
     * most often). Recalculating the totals must not throw the typed date away.
     *
     * Losing it is not cosmetic: with no due date on the case and none on the
     * positions, the accessory is zero, exigibility (CPC art. 1013) can no
     * longer be shown, and the summons states no term.
     */
    public function testPositionsWithoutADueDateDoNotEraseTheDueDateOnTheCase(): void
    {
        $case = new LegalCase();
        $case->setAmount('10000.00');
        $case->setDueDate(new \DateTime('2025-03-15'));

        $case->addClaimItem($this->item('6000.00', null));
        $case->addClaimItem($this->item('4000.00', null));

        $this->service->recalculate($case);

        $this->assertSame('10000.00', $case->getAmount());
        $this->assertSame(
            '2025-03-15',
            $case->getDueDate()?->format('Y-m-d'),
            'The due date the lawyer entered was overwritten with null by the positions.'
        );
    }

    /**
     * Same defect on the invoice identity: positions that carry no number and no
     * date must not blank out what the case already held.
     */
    public function testPositionsWithoutAnInvoiceIdentityDoNotEraseTheCaseInvoiceFields(): void
    {
        $case = new LegalCase();
        $case->setAmount('10000.00');
        $case->setInvoiceNumber('FF-1000');
        $case->setInvoiceDate(new \DateTime('2025-02-01'));

        $case->addClaimItem($this->item('10000.00', '2025-03-15'));

        $this->service->recalculate($case);

        $this->assertSame(
            'FF-1000',
            $case->getInvoiceNumber(),
            'The invoice number on the case was overwritten with null by a position that has none.'
        );
        $this->assertSame('2025-02-01', $case->getInvoiceDate()?->format('Y-m-d'));
    }

    /**
     * A mix is the realistic case: one invoice carries a due date, one does not.
     * The one that does decides the case scalar, and nothing is silently lost.
     */
    public function testTheEarliestDatedPositionStillDrivesTheCaseWhenAnotherHasNoDate(): void
    {
        $case = new LegalCase();
        $case->setAmount('1.00');
        $case->addClaimItem($this->item('4000.00', null));
        $case->addClaimItem($this->item('6000.00', '2025-04-30', 'FF-2', '2025-04-01'));

        $this->service->recalculate($case);

        $this->assertSame('10000.00', $case->getAmount());
        $this->assertSame('2025-04-30', $case->getDueDate()?->format('Y-m-d'));
        $this->assertSame('FF-2', $case->getInvoiceNumber());
    }

    /**
     * Excluding every position leaves the case advertising a principal that no
     * position supports any more. Nothing counts, so the total is zero, and the
     * stale figure is the one that ends up in the petition.
     */
    public function testExcludingEveryPositionDoesNotLeaveAStalePrincipalOnTheCase(): void
    {
        $case = new LegalCase();
        $case->setAmount('10000.00');
        $case->setDueDate(new \DateTime('2025-03-15'));
        $case->addClaimItem($this->item('10000.00', '2025-03-15')->setExcludedByLawyer(true));

        $totals = $this->service->recalculate($case);

        $this->assertSame(0.0, $totals->principalRon);
        $this->assertNotSame(
            '10000.00',
            $case->getAmount(),
            'Every position is excluded, yet the case still claims the full principal.'
        );
    }

    /**
     * Totals never impute a payment, not even one larger than the position.
     */
    public function testAnOverpaymentIsReportedAndStillNotDeducted(): void
    {
        $item = $this->item('1000.00', '2025-01-31')->setPaidAmount('1500.00');

        $totals = $this->service->totals([$item]);

        $this->assertSame(1000.0, $totals->principalRon);
        $this->assertSame(1500.0, $totals->unimputedPaidTotal);
    }

    private function item(
        string $amount,
        ?string $dueDate,
        ?string $documentNumber = null,
        ?string $documentDate = null,
    ): ClaimItem {
        $item = new ClaimItem();
        $item->setAmount($amount);
        $item->setAmountRon($amount);
        $item->setCurrency('RON');
        $item->setDueDate($dueDate !== null ? new \DateTimeImmutable($dueDate) : null);
        $item->setDocumentNumber($documentNumber);
        $item->setDocumentDate($documentDate !== null ? new \DateTimeImmutable($documentDate) : null);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:' . ($documentNumber ?? $amount . ($dueDate ?? 'x')));

        return $item;
    }
}
