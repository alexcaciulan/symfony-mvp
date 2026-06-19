<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\InterestRateConfig;
use App\Entity\LegalCase;
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
        $case->setPaymentNoticeDate(new \DateTime('2025-04-01')); // 90 days, rate 14.5%

        $ctx = $builder->build($case);

        $expected = 5000.0 * 14.5 / 100.0 * 90.0 / 365.0;
        self::assertSame(PenaltyType::LEGAL_PENALIZATOARE, $ctx['penaltyType']);
        self::assertNotNull($ctx['interestResult']);
        self::assertNull($ctx['penaltyResult']);
        self::assertEqualsWithDelta($expected, $ctx['accessoryTotal'], 0.01);
        self::assertEqualsWithDelta(5000.0 + $expected, $ctx['grandTotal'], 0.01);
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
