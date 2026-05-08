<?php

namespace App\Tests\Service\Calculation;

use App\Entity\InterestRateConfig;
use App\Enum\InterestKind;
use App\Enum\RelationshipType;
use App\Repository\InterestRateConfigRepository;
use App\Service\Calculation\InterestCalculatorService;
use PHPUnit\Framework\TestCase;

class InterestCalculatorServiceTest extends TestCase
{
    public function testCommercialPenaltySingleRateOverNinetyDays(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2024-01-01', '6.00'),
        ]));

        $result = $service->calculate(
            amount: 10_000.0,
            dueDate: new \DateTimeImmutable('2024-06-01'),
            referenceDate: new \DateTimeImmutable('2024-08-30'),
            relationshipType: RelationshipType::COMERCIAL,
            kind: InterestKind::PENALIZATOARE,
        );

        $this->assertCount(1, $result->breakdown);
        $period = $result->breakdown[0];
        $this->assertSame(90, $period->days);
        $this->assertSame(6.0, $period->nbrRate);
        $this->assertSame(14.0, $period->applicableRate);

        $expected = 10_000.0 * 14.0 / 100.0 * 90.0 / 365.0;
        $this->assertEqualsWithDelta($expected, $result->total, 0.01);
        $this->assertEqualsWithDelta($expected, $period->periodInterest, 0.01);
    }

    public function testCivilPenaltySpansMultipleRateChanges(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2024-01-01', '7.00'),
            $this->makeConfig('2024-08-01', '6.50'),
            $this->makeConfig('2025-08-01', '6.00'),
        ]));

        $result = $service->calculate(
            amount: 50_000.0,
            dueDate: new \DateTimeImmutable('2024-06-01'),
            referenceDate: new \DateTimeImmutable('2025-12-01'),
            relationshipType: RelationshipType::CIVIL,
            kind: InterestKind::PENALIZATOARE,
        );

        $this->assertCount(3, $result->breakdown);

        [$p1, $p2, $p3] = $result->breakdown;

        // CIVIL + PENALIZATOARE: (BNR + 8) × 0.80 — confirm formula is the revised one, NOT the deprecated `BNR + 4`.
        $this->assertSame(7.0, $p1->nbrRate);
        $this->assertEqualsWithDelta((7.0 + 8.0) * 0.80, $p1->applicableRate, 0.0001);
        $this->assertSame(61, $p1->days);

        $this->assertSame(6.5, $p2->nbrRate);
        $this->assertEqualsWithDelta((6.5 + 8.0) * 0.80, $p2->applicableRate, 0.0001);
        $this->assertSame(365, $p2->days);

        $this->assertSame(6.0, $p3->nbrRate);
        $this->assertEqualsWithDelta((6.0 + 8.0) * 0.80, $p3->applicableRate, 0.0001);
        $this->assertSame(122, $p3->days);

        $this->assertSame(548, $p1->days + $p2->days + $p3->days);

        $expected = 50_000.0 * (
            ((7.0 + 8.0) * 0.80) / 100.0 * 61.0 / 365.0
            + ((6.5 + 8.0) * 0.80) / 100.0 * 365.0 / 365.0
            + ((6.0 + 8.0) * 0.80) / 100.0 * 122.0 / 365.0
        );
        $this->assertEqualsWithDelta($expected, $result->total, 0.01);
    }

    public function testCommercialRemunerativeUsesBnrWithoutSurcharge(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2024-01-01', '6.00'),
        ]));

        $result = $service->calculate(
            amount: 10_000.0,
            dueDate: new \DateTimeImmutable('2024-06-01'),
            referenceDate: new \DateTimeImmutable('2024-08-30'),
            relationshipType: RelationshipType::COMERCIAL,
            kind: InterestKind::REMUNERATORIE,
        );

        $this->assertCount(1, $result->breakdown);
        $period = $result->breakdown[0];
        $this->assertSame(6.0, $period->applicableRate);

        $expected = 10_000.0 * 6.0 / 100.0 * 90.0 / 365.0;
        $this->assertEqualsWithDelta($expected, $result->total, 0.01);
    }

    public function testCivilRemunerativeUsesBnrTimesEightyPercent(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2024-01-01', '6.00'),
        ]));

        $result = $service->calculate(
            amount: 10_000.0,
            dueDate: new \DateTimeImmutable('2024-06-01'),
            referenceDate: new \DateTimeImmutable('2024-08-30'),
            relationshipType: RelationshipType::CIVIL,
            kind: InterestKind::REMUNERATORIE,
        );

        $this->assertCount(1, $result->breakdown);
        $period = $result->breakdown[0];
        $this->assertEqualsWithDelta(6.0 * 0.80, $period->applicableRate, 0.0001);

        $expected = 10_000.0 * (6.0 * 0.80) / 100.0 * 90.0 / 365.0;
        $this->assertEqualsWithDelta($expected, $result->total, 0.01);
    }

    public function testDueDateEqualsReferenceDateReturnsZero(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2024-01-01', '6.00'),
        ]));

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $service->calculate(1_000.0, $sameDate, $sameDate, RelationshipType::COMERCIAL);

        $this->assertSame(0.0, $result->total);
        $this->assertSame([], $result->breakdown);
    }

    public function testDueDateAfterReferenceDateReturnsZero(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2024-01-01', '6.00'),
        ]));

        $result = $service->calculate(
            1_000.0,
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2024-06-01'),
            RelationshipType::COMERCIAL,
        );

        $this->assertSame(0.0, $result->total);
        $this->assertSame([], $result->breakdown);
    }

    public function testLongPeriodBeyondPrescriptionStillCalculates(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2021-01-01', '8.00'),
            $this->makeConfig('2024-01-01', '7.00'),
            $this->makeConfig('2024-08-01', '6.50'),
        ]));

        $result = $service->calculate(
            amount: 20_000.0,
            dueDate: new \DateTimeImmutable('2022-01-01'),
            referenceDate: new \DateTimeImmutable('2025-12-01'),
            relationshipType: RelationshipType::COMERCIAL,
            kind: InterestKind::PENALIZATOARE,
        );

        $this->assertGreaterThan(0.0, $result->total);
        $this->assertCount(3, $result->breakdown);
        $this->assertSame(8.0, $result->breakdown[0]->nbrRate);
        $this->assertSame(7.0, $result->breakdown[1]->nbrRate);
        $this->assertSame(6.5, $result->breakdown[2]->nbrRate);
    }

    public function testThrowsWhenNoRateConfiguredBeforeDueDate(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2025-01-01', '6.00'),
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exception.calculation.interest_rate_missing');

        $service->calculate(
            1_000.0,
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2025-06-01'),
            RelationshipType::COMERCIAL,
        );
    }

    public function testThrowsWhenCurrencyIsNotRon(): void
    {
        $service = new InterestCalculatorService($this->makeRepo([
            $this->makeConfig('2024-01-01', '6.00'),
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exception.calculation.currency_unsupported');

        $service->calculate(
            amount: 1_000.0,
            dueDate: new \DateTimeImmutable('2024-06-01'),
            referenceDate: new \DateTimeImmutable('2024-08-30'),
            relationshipType: RelationshipType::COMERCIAL,
            kind: InterestKind::PENALIZATOARE,
            currency: 'EUR',
        );
    }

    private function makeConfig(string $validFrom, string $rate): InterestRateConfig
    {
        $config = new InterestRateConfig();
        $config->setValidFrom(new \DateTimeImmutable($validFrom));
        $config->setReferenceRate($rate);

        return $config;
    }

    /** @param InterestRateConfig[] $configs */
    private function makeRepo(array $configs): InterestRateConfigRepository
    {
        return new class($configs) extends InterestRateConfigRepository {
            /** @param InterestRateConfig[] $configs */
            public function __construct(private array $configs)
            {
                // intentionally skip parent constructor — only findAllValidUpTo() is exercised
            }

            public function findAllValidUpTo(\DateTimeInterface $date): array
            {
                $matching = array_filter(
                    $this->configs,
                    fn(InterestRateConfig $c) => $c->getValidFrom() <= $date,
                );
                usort(
                    $matching,
                    fn(InterestRateConfig $a, InterestRateConfig $b) => $a->getValidFrom() <=> $b->getValidFrom(),
                );

                return array_values($matching);
            }
        };
    }
}
