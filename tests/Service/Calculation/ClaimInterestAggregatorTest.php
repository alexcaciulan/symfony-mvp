<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Entity\ClaimItem;
use App\Entity\InterestRateConfig;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Repository\InterestRateConfigRepository;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\InterestCalculatorService;
use PHPUnit\Framework\TestCase;

class ClaimInterestAggregatorTest extends TestCase
{
    private const REFERENCE = '2026-01-01';

    public function testThreeInvoicesAccrueFromTheirOwnDueDates(): void
    {
        $calculator = $this->interestCalculator();
        $aggregator = new ClaimInterestAggregator($calculator, new ContractualPenaltyCalculator());

        $items = [
            $this->item(1, '1000.00', '2025-01-31'),
            $this->item(2, '2000.00', '2025-06-30'),
            $this->item(3, '3000.00', '2025-09-30'),
        ];

        $result = $aggregator->aggregate(
            items: $items,
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $expected = 0.0;
        foreach ([[1000.0, '2025-01-31'], [2000.0, '2025-06-30'], [3000.0, '2025-09-30']] as [$amount, $dueDate]) {
            $expected += $calculator->calculate(
                $amount,
                new \DateTimeImmutable($dueDate),
                new \DateTimeImmutable(self::REFERENCE),
                RelationshipType::COMERCIAL,
            )->total;
        }

        $this->assertSame(round($expected, 2), $result->total);
        $this->assertCount(3, $result->interestByItemId);
        $this->assertSame([], $result->skippedItemIds);
    }

    public function testTheAggregateIsLowerThanRunningTheTotalFromTheEarliestDueDate(): void
    {
        // The defect this exists to fix: one calculator call on 6000 from the
        // oldest due date claims interest on sums that were not yet owed then.
        $calculator = $this->interestCalculator();
        $aggregator = new ClaimInterestAggregator($calculator, new ContractualPenaltyCalculator());

        $result = $aggregator->aggregate(
            items: [
                $this->item(1, '1000.00', '2025-01-31'),
                $this->item(2, '2000.00', '2025-06-30'),
                $this->item(3, '3000.00', '2025-09-30'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $naive = $calculator->calculate(
            6000.0,
            new \DateTimeImmutable('2025-01-31'),
            new \DateTimeImmutable(self::REFERENCE),
            RelationshipType::COMERCIAL,
        )->total;

        $this->assertLessThan($naive, $result->total);
    }

    public function testRoundingHappensOnceOnTheSum(): void
    {
        $calculator = $this->interestCalculator();
        $aggregator = new ClaimInterestAggregator($calculator, new ContractualPenaltyCalculator());

        $items = [
            $this->item(1, '333.33', '2025-01-31'),
            $this->item(2, '333.33', '2025-01-31'),
            $this->item(3, '333.33', '2025-01-31'),
        ];

        $result = $aggregator->aggregate(
            items: $items,
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $unrounded = 0.0;
        $roundedPerItem = 0.0;
        foreach ($items as $item) {
            $one = $calculator->calculate(
                (float) $item->getAmountRon(),
                new \DateTimeImmutable('2025-01-31'),
                new \DateTimeImmutable(self::REFERENCE),
                RelationshipType::COMERCIAL,
            )->total;
            $unrounded += $one;
            $roundedPerItem += round($one, 2);
        }

        $this->assertSame(round($unrounded, 2), $result->total);
        $this->assertNotSame($roundedPerItem, $unrounded);
    }

    public function testPositionsWithoutADueDateOrABalanceAreReportedNotDropped(): void
    {
        $aggregator = new ClaimInterestAggregator($this->interestCalculator(), new ContractualPenaltyCalculator());

        $noDueDate = $this->item(7, '1000.00', null);
        $zero = $this->item(8, '0.00', '2025-01-31');

        $result = $aggregator->aggregate(
            items: [$noDueDate, $zero],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $this->assertSame(0.0, $result->total);
        $this->assertSame([7, 8], $result->skippedItemIds);
    }

    public function testAContractualPenaltyIsAlsoComputedPerPosition(): void
    {
        $aggregator = new ClaimInterestAggregator($this->interestCalculator(), new ContractualPenaltyCalculator());

        $result = $aggregator->aggregate(
            items: [
                $this->item(1, '1000.00', '2025-12-02'),
                $this->item(2, '1000.00', '2025-12-22'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualDailyRate: 0.1,
        );

        // 30 days and 10 days at 0,10%/day on 1000 each.
        $this->assertSame(40.0, $result->total);
        $this->assertCount(2, $result->penaltyByItemId);
        $this->assertSame([], $result->interestByItemId);
    }

    private function item(int $id, string $amount, ?string $dueDate): ClaimItem
    {
        $item = new ClaimItem();
        $item->setAmount($amount);
        $item->setAmountRon($amount);
        $item->setCurrency('RON');
        $item->setDueDate($dueDate !== null ? new \DateTimeImmutable($dueDate) : null);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:' . $id);

        $reflection = new \ReflectionProperty(ClaimItem::class, 'id');
        $reflection->setValue($item, $id);

        return $item;
    }

    private function interestCalculator(): InterestCalculatorService
    {
        $configs = [
            $this->rate('2024-01-01', '6.00'),
            $this->rate('2025-05-01', '6.50'),
        ];

        $repository = new class($configs) extends InterestRateConfigRepository {
            /** @param list<InterestRateConfig> $configs */
            public function __construct(private array $configs)
            {
                // Only findAllValidUpTo() is exercised; the parent needs a registry we do not have.
            }

            public function findAllValidUpTo(\DateTimeInterface $date): array
            {
                return array_values(array_filter(
                    $this->configs,
                    static fn (InterestRateConfig $c): bool => $c->getValidFrom() <= $date,
                ));
            }
        };

        return new InterestCalculatorService($repository);
    }

    private function rate(string $validFrom, string $rate): InterestRateConfig
    {
        $config = new InterestRateConfig();
        $config->setValidFrom(new \DateTimeImmutable($validFrom));
        $config->setReferenceRate($rate);

        return $config;
    }
}
