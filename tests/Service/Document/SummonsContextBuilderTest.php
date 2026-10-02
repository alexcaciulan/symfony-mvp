<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\ClaimItem;
use App\Entity\InterestRateConfig;
use App\Entity\LegalCase;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Repository\InterestRateConfigRepository;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Document\SummonsContextBuilder;
use PHPUnit\Framework\TestCase;

final class SummonsContextBuilderTest extends TestCase
{
    public function testLegalPenaltyBranchUsesInterestCalculator(): void
    {
        $builder = $this->makeBuilder(['2024-01-01' => '6.50']);

        $case = $this->makeCase(PenaltyType::LEGAL_PENALIZATOARE);
        $case->setAmount('5000.00');
        $case->setDueDate(new \DateTime('2025-01-01'));
        $case->setInvoiceDate(new \DateTime('2024-12-01'));
        $case->setPaymentNoticeDate(new \DateTime('2025-04-01')); // 90 days, rate 14.5%

        $ctx = $builder->build($case);

        $expected = 5000.0 * 14.5 / 100.0 * 90.0 / 365.0;
        self::assertSame(PenaltyType::LEGAL_PENALIZATOARE, $ctx['penaltyType']);
        self::assertNotNull($ctx['interestResult']);
        self::assertNull($ctx['penaltyResult']);
        self::assertEqualsWithDelta($expected, $ctx['accessoryTotal'], 0.01);
        self::assertEqualsWithDelta(5000.0 + $expected, $ctx['grandTotal'], 0.01);
        // invoiceDate flows from the case into both the context and the audit result.
        self::assertEquals(new \DateTimeImmutable('2024-12-01'), $ctx['invoiceDate']);
        self::assertEquals(new \DateTimeImmutable('2024-12-01'), $ctx['interestResult']->invoiceDate);
    }

    public function testContractualBranchUsesPenaltyCalculator(): void
    {
        $builder = $this->makeBuilder(['2024-01-01' => '6.50']);

        $case = $this->makeCase(PenaltyType::CONTRACTUAL);
        $case->setAmount('175525.00');
        $case->setContractualPenaltyRate('0.100');
        $due = new \DateTime('2025-02-18');
        $case->setDueDate($due);
        $case->setPaymentNoticeDate((clone $due)->modify('+51 days'));

        $ctx = $builder->build($case);

        self::assertSame(PenaltyType::CONTRACTUAL, $ctx['penaltyType']);
        self::assertNull($ctx['interestResult']);
        self::assertNotNull($ctx['penaltyResult']);
        self::assertEqualsWithDelta(8951.78, $ctx['accessoryTotal'], 0.01);
        self::assertEqualsWithDelta(175525.0 + 8951.78, $ctx['grandTotal'], 0.01);
    }

    public function testTheSummonsClaimsNoMoreThanTheContractCeiling(): void
    {
        $builder = $this->makeBuilder(['2024-01-01' => '6.50']);

        $case = $this->makeCase(PenaltyType::CONTRACTUAL);
        $case->setAmount('21318.90');
        $case->setContractualPenaltyRate('0.100');
        $case->setContractualPenaltyCapPercent('10.00');
        $case->setDueDate(new \DateTime('2025-02-21'));
        $case->setPaymentNoticeDate(new \DateTime('2026-10-02'));

        $ctx = $builder->build($case);

        self::assertEqualsWithDelta(2131.89, $ctx['accessoryTotal'], 0.01);
        self::assertTrue($ctx['penaltyResult']->isCapped());
    }

    public function testNullDueDateFallsBackToStoredCalculatedInterest(): void
    {
        $builder = $this->makeBuilder(['2024-01-01' => '6.50']);

        $case = $this->makeCase(PenaltyType::LEGAL_PENALIZATOARE);
        $case->setAmount('5000.00');
        $case->setDueDate(null);
        $case->setCalculatedInterest('420.00');

        $ctx = $builder->build($case);

        self::assertNull($ctx['interestResult']);
        self::assertSame(420.0, $ctx['accessoryTotal']);
        self::assertSame(5420.0, $ctx['grandTotal']);
    }

    public function testNullPenaltyTypeDefaultsToLegal(): void
    {
        $builder = $this->makeBuilder(['2024-01-01' => '6.50']);

        $case = $this->makeCase(null);
        $case->setAmount('5000.00');
        $case->setDueDate(new \DateTime('2025-01-01'));
        $case->setPaymentNoticeDate(new \DateTime('2025-04-01'));

        $ctx = $builder->build($case);

        self::assertSame(PenaltyType::LEGAL_PENALIZATOARE, $ctx['penaltyType']);
        self::assertNotNull($ctx['interestResult']);
    }

    public function testContractualCaseDefaultsToLatePaymentPenaltiesLabel(): void
    {
        $case = $this->makeCase(PenaltyType::CONTRACTUAL);
        $case->setAmount('1000.00');

        $ctx = $this->makeBuilder(['2024-01-01' => '6.50'])->build($case);

        self::assertTrue($ctx['isContractual']);
        self::assertSame(ContractualAccessoryLabel::PENALITATI_INTARZIERE, $ctx['accessoryLabel']);
    }

    public function testLegalCaseExposesNoAccessoryLabel(): void
    {
        $case = $this->makeCase(PenaltyType::LEGAL_PENALIZATOARE);
        $case->setAmount('1000.00');
        $case->setContractualAccessoryLabel(ContractualAccessoryLabel::MAJORARI_INTARZIERE);

        $ctx = $this->makeBuilder(['2024-01-01' => '6.50'])->build($case);

        self::assertFalse($ctx['isContractual']);
        self::assertNull($ctx['accessoryLabel']);
    }

    public function testDailyRateIsFormattedWithoutTrailingZeros(): void
    {
        $builder = $this->makeBuilder(['2024-01-01' => '6.50']);

        foreach (['0.100' => '0,1', '0.015' => '0,015', '1.000' => '1', '0.250' => '0,25'] as $stored => $shown) {
            $case = $this->makeCase(PenaltyType::CONTRACTUAL);
            $case->setContractualPenaltyRate($stored);

            self::assertSame($shown, $builder->build($case)['dailyRateFormatted']);
        }
    }

    public function testCalculationStartsTheDayAfterTheSingleDueDate(): void
    {
        $case = $this->makeCase(PenaltyType::CONTRACTUAL);
        $case->setAmount('1000.00');
        $case->setDueDate(new \DateTime('2025-02-18'));

        $ctx = $this->makeBuilder(['2024-01-01' => '6.50'])->build($case);

        self::assertSame('2025-02-19', $ctx['calculationStart']->format('Y-m-d'));
    }

    public function testNamedContractNeedsANumberOrADate(): void
    {
        $builder = $this->makeBuilder(['2024-01-01' => '6.50']);
        $case = $this->makeCase(PenaltyType::LEGAL_PENALIZATOARE);

        self::assertNull($builder->build($case)['namedContract']);

        $case->setContractNumber('12');
        self::assertSame('12', $builder->build($case)['namedContract']['number']);
    }

    public function testLumpSumAccessoryIsRoundedToTheBan(): void
    {
        $case = $this->makeCase(PenaltyType::LEGAL_PENALIZATOARE);
        $case->setAmount('5000.00');
        $case->setDueDate(new \DateTime('2025-01-01'));
        $case->setPaymentNoticeDate(new \DateTime('2025-04-01'));

        $ctx = $this->makeBuilder(['2024-01-01' => '6.50'])->build($case);

        self::assertSame(round($ctx['accessoryTotal'], 2), $ctx['accessoryTotal']);
    }

    /**
     * Positions that cannot accrue on their own (no due date) leave the
     * accessory to the case-level computation; its rows must still be shown.
     */
    public function testCaseLevelAccessoryIsTabulatedWhenNoPositionCanAccrue(): void
    {
        $case = $this->makeCase(PenaltyType::LEGAL_PENALIZATOARE);
        $case->setAmount('5000.00');
        $case->setDueDate(new \DateTime('2025-01-01'));
        $case->setPaymentNoticeDate(new \DateTime('2025-04-01'));
        $item = new ClaimItem();
        $item->setAmount('5000.00');
        $item->setAmountRon('5000.00');
        $item->setCurrency('RON');
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:1');
        $case->addClaimItem($item);

        $ctx = $this->makeBuilder(['2024-01-01' => '6.50'])->build($case);

        self::assertGreaterThan(0.0, $ctx['accessoryTotal']);
        self::assertCount(1, $ctx['summonsTables']->principalRows);
        self::assertNotEmpty($ctx['summonsTables']->legalInterestRows);
        self::assertSame(90, $ctx['summonsTables']->legalInterestRows[0]->days);
    }

    /** @param array<string,string> $rates validFrom => referenceRate */
    private function makeBuilder(array $rates): SummonsContextBuilder
    {
        $configs = [];
        foreach ($rates as $validFrom => $rate) {
            $config = new InterestRateConfig();
            $config->setValidFrom(new \DateTimeImmutable($validFrom));
            $config->setReferenceRate($rate);
            $configs[] = $config;
        }

        $repo = new class($configs) extends InterestRateConfigRepository {
            /** @param InterestRateConfig[] $configs */
            public function __construct(private array $configs) {}

            public function findAllValidUpTo(\DateTimeInterface $date): array
            {
                $matching = array_filter($this->configs, fn(InterestRateConfig $c) => $c->getValidFrom() <= $date);
                usort($matching, fn(InterestRateConfig $a, InterestRateConfig $b) => $a->getValidFrom() <=> $b->getValidFrom());

                return array_values($matching);
            }
        };

        return new SummonsContextBuilder(new InterestCalculatorService($repo), new ContractualPenaltyCalculator());
    }

    private function makeCase(?PenaltyType $penaltyType): LegalCase
    {
        $case = new LegalCase();
        $case->setCurrency('RON');
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        if ($penaltyType !== null) {
            $case->setPenaltyType($penaltyType);
        }

        return $case;
    }
}
