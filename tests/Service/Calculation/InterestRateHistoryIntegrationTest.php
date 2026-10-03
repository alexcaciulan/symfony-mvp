<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Enum\RelationshipType;
use App\Service\Calculation\InterestCalculatorService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the calculator against the reference rates actually stored, so a claim
 * that fell due before the oldest stored rate shows up as a failure here rather
 * than as an "undetermined" interest in front of the lawyer.
 */
final class InterestRateHistoryIntegrationTest extends KernelTestCase
{
    public function testInvoiceDueIn2022UsesTheRatesInForceThen(): void
    {
        self::bootKernel();
        $calculator = self::getContainer()->get(InterestCalculatorService::class);

        $result = $calculator->calculate(
            amount: 27_340.0,
            dueDate: new \DateTimeImmutable('2022-04-07'),
            referenceDate: new \DateTimeImmutable('2023-01-10'),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $rates = array_map(static fn ($p): float => $p->nbrRate, $result->breakdown);
        // 3.00 from 2022-04-06, then each BNR change before 7.00 on 2023-01-11.
        self::assertSame([3.0, 3.75, 4.75, 5.5, 6.25, 6.75], $rates);
        self::assertSame('2022-05-11', $result->breakdown[1]->startDate->format('Y-m-d'));
        self::assertSame(278, array_sum(array_map(static fn ($p): int => $p->days, $result->breakdown)));
        self::assertGreaterThan(0.0, $result->total);
    }

    public function testEveryDueDateUnderTheProfessionalMarginHasAStartingRate(): void
    {
        self::bootKernel();
        $calculator = self::getContainer()->get(InterestCalculatorService::class);

        foreach (['2013-04-05', '2015-06-30', '2019-12-31', '2021-11-09'] as $due) {
            $result = $calculator->calculate(
                amount: 1_000.0,
                dueDate: new \DateTimeImmutable($due),
                referenceDate: new \DateTimeImmutable('2024-01-01'),
                relationshipType: RelationshipType::COMERCIAL,
            );
            self::assertNotEmpty($result->breakdown, 'No rate for a claim due on ' . $due);
        }
    }

    public function testRatesStoredMatchTheBnrCircularsAtKnownDates(): void
    {
        self::bootKernel();
        $calculator = self::getContainer()->get(InterestCalculatorService::class);

        $expected = ['2015-05-07' => 1.75, '2018-05-08' => 2.5, '2020-03-23' => 2.0, '2022-02-10' => 2.5];
        foreach ($expected as $due => $rate) {
            $result = $calculator->calculate(
                amount: 1_000.0,
                dueDate: new \DateTimeImmutable($due),
                referenceDate: (new \DateTimeImmutable($due))->modify('+1 day'),
                relationshipType: RelationshipType::COMERCIAL,
            );
            self::assertSame($rate, $result->breakdown[0]->nbrRate, 'Rate in force on ' . $due);
        }
    }

    public function testAClaimDueBeforeLaw72Of2013IsNotComputedWithItsMargin(): void
    {
        self::bootKernel();
        $calculator = self::getContainer()->get(InterestCalculatorService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exception.calculation.before_professional_margin');

        $calculator->calculate(
            amount: 1_000.0,
            dueDate: new \DateTimeImmutable('2013-04-04'),
            referenceDate: new \DateTimeImmutable('2014-01-01'),
            relationshipType: RelationshipType::COMERCIAL,
        );
    }

    public function testAnInvoiceUnderAContractOlderThanLaw72Of2013IsNotComputedWithItsMargin(): void
    {
        self::bootKernel();
        $calculator = self::getContainer()->get(InterestCalculatorService::class);

        $this->expectExceptionMessage('exception.calculation.contract_before_professional_margin');

        $calculator->calculate(
            amount: 1_000.0,
            dueDate: new \DateTimeImmutable('2015-03-01'),
            referenceDate: new \DateTimeImmutable('2016-01-01'),
            relationshipType: RelationshipType::COMERCIAL,
            contractDate: new \DateTimeImmutable('2012-11-20'),
        );
    }

    public function testAContractConcludedAfterLaw72Of2013KeepsTheMargin(): void
    {
        self::bootKernel();
        $calculator = self::getContainer()->get(InterestCalculatorService::class);

        $result = $calculator->calculate(
            amount: 1_000.0,
            dueDate: new \DateTimeImmutable('2015-03-01'),
            referenceDate: new \DateTimeImmutable('2015-03-31'),
            relationshipType: RelationshipType::COMERCIAL,
            contractDate: new \DateTimeImmutable('2013-04-05'),
        );

        self::assertSame(10.25, $result->breakdown[0]->applicableRate);
    }
}
