<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\FiscalInvoiceLine;
use App\Service\Billing\VatCalculator;
use PHPUnit\Framework\TestCase;

class VatCalculatorTest extends TestCase
{
    private VatCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new VatCalculator();
    }

    public function testStandardRate19(): void
    {
        $totals = $this->calc->lineTotals('99.00', '1', '19');

        $this->assertSame('99.00', $totals['net']);
        $this->assertSame('18.81', $totals['vat']);
        $this->assertSame('117.81', $totals['gross']);
    }

    public function testProRate(): void
    {
        $totals = $this->calc->lineTotals('299.00', '1', '19');

        $this->assertSame('299.00', $totals['net']);
        $this->assertSame('56.81', $totals['vat']);
        $this->assertSame('355.81', $totals['gross']);
    }

    public function testHalfUpRounding(): void
    {
        // 0.10 * 19% = 0.019 -> rounds half-up to 0.02
        $totals = $this->calc->lineTotals('0.10', '1', '19');

        $this->assertSame('0.10', $totals['net']);
        $this->assertSame('0.02', $totals['vat']);
        $this->assertSame('0.12', $totals['gross']);
    }

    public function testQuantityMultiplies(): void
    {
        $totals = $this->calc->lineTotals('50.00', '3', '19');

        $this->assertSame('150.00', $totals['net']);
        $this->assertSame('28.50', $totals['vat']);
        $this->assertSame('178.50', $totals['gross']);
    }

    public function testGrossEqualsNetPlusVat(): void
    {
        $totals = $this->calc->lineTotals('123.45', '1', '19');

        $this->assertSame(
            number_format((float) $totals['net'] + (float) $totals['vat'], 2, '.', ''),
            $totals['gross'],
        );
    }

    public function testBreakdownAggregatesLines(): void
    {
        $lines = [
            $this->line('99.00', '18.81', '117.81', '19.00'),
            $this->line('50.00', '9.50', '59.50', '19.00'),
        ];

        $breakdown = $this->calc->breakdown($lines);

        $this->assertSame('149.00', $breakdown->netTotal);
        $this->assertSame('28.31', $breakdown->vatTotal);
        $this->assertSame('177.31', $breakdown->grossTotal);
        $this->assertCount(1, $breakdown->byRate);
        $this->assertSame('19.00', $breakdown->byRate[0]['rate']);
    }

    public function testStornoNegativeAmountProducesNegativeVat(): void
    {
        $totals = $this->calc->lineTotals('-0.10', '1', '19');

        $this->assertSame('-0.10', $totals['net']);
        $this->assertSame('-0.02', $totals['vat']);
        $this->assertSame('-0.12', $totals['gross']);
    }

    private function line(string $net, string $vat, string $gross, string $rate): FiscalInvoiceLine
    {
        return (new FiscalInvoiceLine())
            ->setDescription('x')
            ->setUnitPriceNet($net)
            ->setVatRate($rate)
            ->setLineNet($net)
            ->setLineVat($vat)
            ->setLineGross($gross);
    }
}
