<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Entity\ClaimItem;
use App\Entity\InterestRateConfig;
use App\Enum\RelationshipType;
use App\Repository\InterestRateConfigRepository;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\InterestCalculatorService;
use PHPUnit\Framework\TestCase;

/**
 * Numbers, not shapes.
 *
 * Every expectation here is a figure computed by hand from the statute and
 * hardcoded, so the test cannot agree with a wrong implementation by asking the
 * implementation what the answer is.
 *
 * Fixture: a single NBR reference rate of 6,00% in force from 2020-01-01, a
 * commercial relationship and penalty interest, so the applicable rate is
 * 6 + 8 = 14% (OG 13/2011 art. 3 alin. 2^1). Interest for a position is then
 *
 *     amount x 0,14 x days / 365
 *
 * with `days` counted from the day after the due date through the reference
 * date (Civil Code art. 1535), which is what diff(dueDate, referenceDate) gives.
 * Reference date is 2025-10-31 (day of year 304 in a non-leap year) throughout.
 */
final class ClaimAccessoryNumericAdversarialTest extends TestCase
{
    private const REFERENCE = '2025-10-31';

    /**
     * One position must reproduce the pre-positions model to the bani.
     *
     * 25.000,00 RON due 2025-03-15 (day 74), reference day 304, so 230 days.
     * 25000 x 0,14 = 3500; 3500 x 230 = 805.000; 805.000 / 365 = 2205,4794520548.
     * Rounded once: 2205,48.
     */
    public function testASinglePositionReproducesTheScalarFigureToTheBani(): void
    {
        $calculator = $this->interestCalculator();
        $aggregator = new ClaimInterestAggregator($calculator, new ContractualPenaltyCalculator());

        $result = $aggregator->aggregate(
            items: [$this->item(1, '25000.00', '2025-03-15')],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $this->assertSame(2205.48, $result->total);
        $this->assertSame([], $result->skippedItemIds);

        // And it is the very same figure the scalar model produced.
        $scalar = $calculator->calculate(
            25000.0,
            new \DateTimeImmutable('2025-03-15'),
            new \DateTimeImmutable(self::REFERENCE),
            RelationshipType::COMERCIAL,
        )->total;
        $this->assertSame(2205.48, round($scalar, 2));
    }

    /**
     * Three invoices of 10.000 RON, three months apart, each accruing from its
     * own due date.
     *
     *   due 2025-01-31 (day 31)  -> 273 days -> 1400 x 273 / 365 = 1047,1232876712
     *   due 2025-04-30 (day 120) -> 184 days -> 1400 x 184 / 365 =  705,7534246575
     *   due 2025-07-31 (day 212) ->  92 days -> 1400 x  92 / 365 =  352,8767123288
     *
     * Sum = 768.600 / 365 = 2105,7534246575 -> 2105,75.
     */
    public function testThreeInvoicesAccrueEachFromItsOwnDueDate(): void
    {
        $aggregator = new ClaimInterestAggregator($this->interestCalculator(), new ContractualPenaltyCalculator());

        $result = $aggregator->aggregate(
            items: [
                $this->item(1, '10000.00', '2025-01-31'),
                $this->item(2, '10000.00', '2025-04-30'),
                $this->item(3, '10000.00', '2025-07-31'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $this->assertSame(2105.75, $result->total);
        $this->assertCount(3, $result->interestByItemId);
        $this->assertSame(1047.12, round($result->interestByItemId[1]->total, 2));
        $this->assertSame(705.75, round($result->interestByItemId[2]->total, 2));
        $this->assertSame(352.88, round($result->interestByItemId[3]->total, 2));
    }

    /**
     * The same three invoices run the old way: 30.000 from the earliest due
     * date. 30000 x 0,14 = 4200; 4200 x 273 / 365 = 3141,3698630137 -> 3141,37.
     *
     * The old model asks for 1035,62 RON more than is owed. If the aggregate
     * ever equals this figure, the positions are not being used.
     */
    public function testTheAggregateIsNotTheOldSingleSumFromTheEarliestDueDate(): void
    {
        $calculator = $this->interestCalculator();
        $aggregator = new ClaimInterestAggregator($calculator, new ContractualPenaltyCalculator());

        $perItem = $aggregator->aggregate(
            items: [
                $this->item(1, '10000.00', '2025-01-31'),
                $this->item(2, '10000.00', '2025-04-30'),
                $this->item(3, '10000.00', '2025-07-31'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $naive = round($calculator->calculate(
            30000.0,
            new \DateTimeImmutable('2025-01-31'),
            new \DateTimeImmutable(self::REFERENCE),
            RelationshipType::COMERCIAL,
        )->total, 2);

        $this->assertSame(3141.37, $naive);
        $this->assertNotSame($naive, $perItem->total);
        $this->assertSame(1035.62, round($naive - $perItem->total, 2));
    }

    /**
     * Rounding happens once, on the sum, and it is numerically visible.
     *
     * Factor for 2025-01-31 -> 2025-10-31 is 0,14 x 273 / 365 = 0,1047123287671.
     *   93,16 x factor =  9,7550005479  (rounds up on its own to 9,76)
     *   96,98 x factor = 10,1550016438  (rounds up on its own to 10,16)
     * Sum first: 19,9100021918 -> 19,91.
     * Round first: 9,76 + 10,16 = 19,92.
     *
     * 19,91 is the figure the court re-adds from the amounts; 19,92 is a ban
     * that does not exist.
     */
    public function testTheSumIsRoundedOnceAndNotPerPosition(): void
    {
        $aggregator = new ClaimInterestAggregator($this->interestCalculator(), new ContractualPenaltyCalculator());

        $result = $aggregator->aggregate(
            items: [
                $this->item(1, '93.16', '2025-01-31'),
                $this->item(2, '96.98', '2025-01-31'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $this->assertSame(19.91, $result->total);
        $this->assertNotSame(19.92, $result->total);
        // The per-position figures really do each round up, so the difference
        // is the rounding policy and not the inputs.
        $this->assertSame(9.76, round($result->interestByItemId[1]->total, 2));
        $this->assertSame(10.16, round($result->interestByItemId[2]->total, 2));
    }

    /**
     * A position with no due date and one with a zero balance are reported, the
     * rest of the claim still computes, and nothing throws.
     */
    public function testPositionsWithoutDueDateOrBalanceAreReportedNextToAValidOne(): void
    {
        $aggregator = new ClaimInterestAggregator($this->interestCalculator(), new ContractualPenaltyCalculator());

        $result = $aggregator->aggregate(
            items: [
                $this->item(1, '10000.00', '2025-01-31'),
                $this->item(2, '5000.00', null),
                $this->item(3, '0.00', '2025-01-31'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $this->assertSame(1047.12, $result->total);
        $this->assertSame([2, 3], $result->skippedItemIds);
        $this->assertArrayNotHasKey(2, $result->interestByItemId);
        $this->assertArrayNotHasKey(3, $result->interestByItemId);
    }

    /**
     * A position awaiting a manual rate carries no RON value. It must be
     * skipped, never treated as zero inside a sum that pretends to be complete,
     * and never allowed to throw out of the aggregator.
     */
    public function testAPositionAwaitingAManualRateIsSkippedWithoutThrowing(): void
    {
        $aggregator = new ClaimInterestAggregator($this->interestCalculator(), new ContractualPenaltyCalculator());

        $pending = $this->item(2, '1000.00', '2025-01-31');
        $pending->setCurrency('EUR')->setAmountRon(null)->setNeedsManualFx(true);

        $result = $aggregator->aggregate(
            items: [$this->item(1, '10000.00', '2025-01-31'), $pending],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $this->assertSame(1047.12, $result->total);
        $this->assertSame([2], $result->skippedItemIds);
    }

    /**
     * A recorded payment is not imputed, so the interest base stays the full
     * amount. Civil Code art. 1507-1509 imputes to costs, then interest, then
     * capital; the platform must not do it silently in either direction.
     */
    public function testARecordedPaymentDoesNotShrinkTheInterestBase(): void
    {
        $aggregator = new ClaimInterestAggregator($this->interestCalculator(), new ContractualPenaltyCalculator());

        $paid = $this->item(1, '10000.00', '2025-01-31')->setPaidAmount('4000.00');

        $result = $aggregator->aggregate(
            items: [$paid],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        // Interest on 10.000, not on 6.000 (which would be 628,27).
        $this->assertSame(1047.12, $result->total);
        $this->assertNotSame(628.27, $result->total);
    }

    /**
     * Unsaved positions have no id yet. They must still each get their own
     * entry rather than collapse onto one key and lose a result.
     */
    public function testUnsavedPositionsDoNotCollapseOntoOneKey(): void
    {
        $aggregator = new ClaimInterestAggregator($this->interestCalculator(), new ContractualPenaltyCalculator());

        $result = $aggregator->aggregate(
            items: [
                $this->item(null, '10000.00', '2025-01-31'),
                $this->item(null, '10000.00', '2025-04-30'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $this->assertCount(2, $result->interestByItemId);
        $this->assertSame(1752.88, $result->total);
    }

    private function item(?int $id, string $amount, ?string $dueDate): ClaimItem
    {
        $item = new ClaimItem();
        $item->setAmount($amount);
        $item->setAmountRon($amount);
        $item->setCurrency('RON');
        $item->setDueDate($dueDate !== null ? new \DateTimeImmutable($dueDate) : null);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:' . ($id ?? 0) . $amount);

        if ($id !== null) {
            (new \ReflectionProperty(ClaimItem::class, 'id'))->setValue($item, $id);
        }

        return $item;
    }

    private function interestCalculator(): InterestCalculatorService
    {
        $config = new InterestRateConfig();
        $config->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $config->setReferenceRate('6.00');

        $repository = new class([$config]) extends InterestRateConfigRepository {
            /** @param list<InterestRateConfig> $configs */
            public function __construct(private array $configs)
            {
                // Only findAllValidUpTo() is exercised; the parent needs a registry we do not have.
            }

            public function findAllValidUpTo(\DateTimeInterface $date): array
            {
                return $this->configs;
            }
        };

        return new InterestCalculatorService($repository);
    }
}
