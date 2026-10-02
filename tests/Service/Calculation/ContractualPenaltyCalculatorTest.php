<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\ContractualPenaltyCalculator;
use PHPUnit\Framework\TestCase;

class ContractualPenaltyCalculatorTest extends TestCase
{
    public function testReproducesRealSummonsFigure(): void
    {
        // Somație reală: principal 175.525 RON, 0,10%/zi, 51 zile → 8.951,78 RON.
        $service = new ContractualPenaltyCalculator();
        $start = new \DateTimeImmutable('2025-02-18');

        $result = $service->calculate(
            amount: 175_525.0,
            dailyRatePercent: 0.10,
            startDate: $start,
            referenceDate: $start->modify('+51 days'),
        );

        $this->assertCount(1, $result->breakdown);
        $period = $result->breakdown[0];
        $this->assertSame(51, $period->days);
        $this->assertSame(0.10, $period->dailyRate);
        $this->assertEqualsWithDelta(8_951.78, $result->total, 0.01);
        $this->assertEqualsWithDelta(8_951.78, $period->periodPenalty, 0.01);
    }

    public function testSinglePeriodInvariant(): void
    {
        $service = new ContractualPenaltyCalculator();
        $result = $service->calculate(
            amount: 10_000.0,
            dailyRatePercent: 0.05,
            startDate: new \DateTimeImmutable('2024-01-01'),
            referenceDate: new \DateTimeImmutable('2025-01-01'),
        );

        $this->assertCount(1, $result->breakdown);
        $this->assertSame(366, $result->breakdown[0]->days);
        $this->assertEqualsWithDelta(10_000.0 * 0.05 / 100.0 * 366.0, $result->total, 0.01);
    }

    public function testZeroOrNegativeRateYieldsNoPenalty(): void
    {
        $service = new ContractualPenaltyCalculator();

        $zero = $service->calculate(10_000.0, 0.0, new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-06-01'));
        $this->assertSame(0.0, $zero->total);
        $this->assertSame([], $zero->breakdown);

        $negative = $service->calculate(10_000.0, -1.0, new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-06-01'));
        $this->assertSame(0.0, $negative->total);
        $this->assertSame([], $negative->breakdown);
    }

    public function testReferenceBeforeOrEqualStartYieldsNoPenalty(): void
    {
        $service = new ContractualPenaltyCalculator();

        $equal = $service->calculate(10_000.0, 0.10, new \DateTimeImmutable('2024-06-01'), new \DateTimeImmutable('2024-06-01'));
        $this->assertSame(0.0, $equal->total);

        $before = $service->calculate(10_000.0, 0.10, new \DateTimeImmutable('2024-06-01'), new \DateTimeImmutable('2024-05-01'));
        $this->assertSame(0.0, $before->total);
    }

    public function testTheContractCeilingStopsThePenalty(): void
    {
        // Contract 129/18.12.2024 (case 5 of the lawyer review): 0,1%/day,
        // "nu poate depăși 10% din valoarea prestației" (art. 10.3), invoice
        // of 21.318,90 RON due 21.02.2025. Uncapped it would reach 12.535,51.
        $result = (new ContractualPenaltyCalculator())->calculate(
            amount: 21_318.90,
            dailyRatePercent: 0.1,
            startDate: new \DateTimeImmutable('2025-02-21'),
            referenceDate: new \DateTimeImmutable('2026-10-02'),
            capPercent: 10.0,
        );

        $this->assertTrue($result->isCapped());
        $this->assertEqualsWithDelta(2_131.89, $result->total, 0.01);
        $this->assertEqualsWithDelta(12_535.51, $result->breakdown[0]->periodPenalty, 0.01, 'the uncapped figure stays visible');
    }

    public function testACeilingNotYetReachedChangesNothing(): void
    {
        $result = (new ContractualPenaltyCalculator())->calculate(
            amount: 10_000.0,
            dailyRatePercent: 0.1,
            startDate: new \DateTimeImmutable('2025-01-01'),
            referenceDate: new \DateTimeImmutable('2025-01-31'),
            capPercent: 10.0,
        );

        $this->assertFalse($result->isCapped());
        $this->assertEqualsWithDelta(300.0, $result->total, 0.01);
    }
}
