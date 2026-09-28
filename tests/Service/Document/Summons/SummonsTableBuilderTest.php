<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Summons;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\Entity\ClaimItem;
use App\Entity\InterestRateConfig;
use App\Entity\LegalCase;
use App\Enum\ClaimItemKind;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Repository\InterestRateConfigRepository;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Document\Summons\SummonsTableBuilder;
use PHPUnit\Framework\TestCase;

final class SummonsTableBuilderTest extends TestCase
{
    private const REFERENCE = '2025-08-31';

    public function testLegalRowsSplitAtEveryNbrRateChange(): void
    {
        $items = [$this->item(1, '1000.00', '2025-04-30', 'F-1')];

        $tables = $this->buildLegal($items);

        self::assertCount(2, $tables->legalInterestRows);
        [$first, $second] = $tables->legalInterestRows;
        self::assertSame('2025-05-01', $first->periodStart->format('Y-m-d'));
        self::assertSame('2025-06-01', $first->periodEnd->format('Y-m-d'));
        self::assertSame(6.0, $first->nbrRate);
        self::assertSame(14.0, $first->applicableRate);
        self::assertSame('2025-06-02', $second->periodStart->format('Y-m-d'));
        self::assertSame(6.5, $second->nbrRate);
        self::assertSame(14.5, $second->applicableRate);
    }

    /** The displayed interval holds exactly the counted days, both ends included. */
    public function testDisplayedPeriodHoldsExactlyTheCountedDays(): void
    {
        $tables = $this->buildLegal([$this->item(1, '1000.00', '2025-04-30', 'F-1')]);

        foreach ($tables->legalInterestRows as $row) {
            $inclusive = (int) $row->periodStart->diff($row->periodEnd)->days + 1;
            self::assertSame($row->days, $inclusive);
        }
    }

    public function testEveryRowEqualsBalanceTimesRateTimesDays(): void
    {
        $tables = $this->buildLegal([
            $this->item(1, '1000.00', '2025-04-30', 'F-1'),
            $this->item(2, '2500.00', '2025-07-15', 'F-2'),
        ]);

        foreach ($tables->legalInterestRows as $row) {
            self::assertEqualsWithDelta($row->balance * $row->applicableRate / 100 * $row->days / 365, $row->interest, 1e-9);
        }
    }

    public function testLegalRowsRenderOneBlockPerDocument(): void
    {
        $tables = $this->buildLegal([
            $this->item(1, '1000.00', '2025-04-30', 'F-1'),
            $this->item(2, '2500.00', '2025-07-15', 'F-2'),
        ]);

        $documents = array_map(static fn ($row) => $row->documentNumber, $tables->legalInterestRows);
        self::assertSame(['F-1', 'F-1', 'F-2'], $documents);
        self::assertCount(2, $tables->principalRows);
    }

    public function testCreditNoteIsNegativeInPrincipalAndAbsentFromAccessoryRows(): void
    {
        $credit = $this->item(3, '200.00', '2025-05-15', 'NC-1');
        $credit->setKind(ClaimItemKind::CREDIT_NOTE);

        $tables = $this->buildLegal([$this->item(1, '1000.00', '2025-04-30', 'F-1'), $credit]);

        self::assertSame(-200.0, $tables->principalRows[1]->amount);
        self::assertNotContains('NC-1', array_map(static fn ($row) => $row->documentNumber, $tables->legalInterestRows));
    }

    public function testItemWithoutDueDateIsListedAsPrincipalWithoutAccessoryRow(): void
    {
        $tables = $this->buildLegal([$this->item(1, '1000.00', '2025-04-30', 'F-1'), $this->item(2, '500.00', null, 'F-2')]);

        self::assertCount(2, $tables->principalRows);
        self::assertNotContains('F-2', array_map(static fn ($row) => $row->documentNumber, $tables->legalInterestRows));
    }

    public function testContractualRowKeepsDueDateAndStartsDelayTheDayAfter(): void
    {
        $items = [$this->item(1, '175525.00', '2025-02-18', 'F-9')];
        $aggregate = $this->aggregator()->aggregate(
            items: $items,
            referenceDate: new \DateTimeImmutable('2025-04-10'),
            relationshipType: RelationshipType::COMERCIAL,
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualDailyRate: 0.1,
        );

        $tables = (new SummonsTableBuilder())->build(new LegalCase(), $items, $aggregate, null, null, $aggregate->total);

        self::assertCount(1, $tables->contractualPenaltyRows);
        $row = $tables->contractualPenaltyRows[0];
        self::assertSame('2025-02-18', $row->dueDate->format('Y-m-d'));
        self::assertSame('2025-02-19', $row->periodStart->format('Y-m-d'));
        self::assertSame(51, $row->days);
        self::assertSame(0.1, $row->dailyRate);
        self::assertEqualsWithDelta(8951.775, $row->penalty, 1e-6);
        self::assertSame([], $tables->legalInterestRows);
    }

    public function testRoundingNoteAppearsOnlyWhenRowsDoNotAddUpToTheTotal(): void
    {
        $items = [$this->item(1, '1000.00', '2025-04-30', 'F-1')];
        $aggregate = $this->aggregate($items);
        $builder = new SummonsTableBuilder();

        $rowsSum = 0.0;
        $tables = $builder->build(new LegalCase(), $items, $aggregate, null, null, $aggregate->total);
        foreach ($tables->legalInterestRows as $row) {
            $rowsSum += round($row->interest, 2);
        }

        self::assertSame(abs(round($rowsSum, 2) - $aggregate->total) >= 0.005, $tables->roundingNoteNeeded);
        self::assertTrue($builder->build(new LegalCase(), $items, $aggregate, null, null, $aggregate->total + 0.01)->roundingNoteNeeded);
    }

    public function testCaseWithoutPositionsYieldsOneRowFromTheCase(): void
    {
        $case = new LegalCase();
        $case->setAmount('5000.00');
        $case->setInvoiceNumber('FCT-1');
        $case->setDueDate(new \DateTime('2025-02-18'));

        $tables = (new SummonsTableBuilder())->build($case, [], null, null, null, 0.0);

        self::assertCount(1, $tables->principalRows);
        self::assertSame('FCT-1', $tables->principalRows[0]->documentNumber);
        self::assertSame(5000.0, $tables->principalRows[0]->amount);
        self::assertFalse($tables->roundingNoteNeeded);
    }

    public function testConvertedItemCarriesItsOwnExchangeRate(): void
    {
        $item = $this->item(1, '1000.00', '2025-04-30', 'F-1');
        $item->setCurrency('EUR');
        $item->setAmountRon('4975.00');
        $item->setExchangeRate('4.9750');
        $item->setExchangeRateDate(new \DateTimeImmutable('2025-03-31'));

        $tables = (new SummonsTableBuilder())->build(new LegalCase(), [$item], null, null, null, 0.0);

        $row = $tables->principalRows[0];
        self::assertSame(4975.0, $row->amount);
        self::assertSame(1000.0, $row->originalAmount);
        self::assertSame('EUR', $row->originalCurrency);
        self::assertSame(4.975, $row->exchangeRate);
    }

    /** @param list<ClaimItem> $items */
    private function buildLegal(array $items): \App\DTO\Summons\SummonsTables
    {
        $aggregate = $this->aggregate($items);

        return (new SummonsTableBuilder())->build(new LegalCase(), $items, $aggregate, null, null, $aggregate->total);
    }

    /** @param list<ClaimItem> $items */
    private function aggregate(array $items): AggregatedAccessoryResult
    {
        return $this->aggregator()->aggregate(
            items: $items,
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
        );
    }

    private function aggregator(): ClaimInterestAggregator
    {
        $configs = [$this->rate('2024-01-01', '6.00'), $this->rate('2025-06-01', '6.50')];

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

        return new ClaimInterestAggregator(new InterestCalculatorService($repository), new ContractualPenaltyCalculator());
    }

    private function item(int $id, string $amount, ?string $dueDate, string $number): ClaimItem
    {
        $item = new ClaimItem();
        $item->setAmount($amount);
        $item->setAmountRon($amount);
        $item->setCurrency('RON');
        $item->setDocumentNumber($number);
        $item->setDueDate($dueDate !== null ? new \DateTimeImmutable($dueDate) : null);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:' . $id);

        (new \ReflectionProperty(ClaimItem::class, 'id'))->setValue($item, $id);

        return $item;
    }

    private function rate(string $validFrom, string $rate): InterestRateConfig
    {
        $config = new InterestRateConfig();
        $config->setValidFrom(new \DateTimeImmutable($validFrom));
        $config->setReferenceRate($rate);

        return $config;
    }
}
