<?php

namespace App\Tests\Service;

use App\Service\Case\TaxCalculatorService;
use PHPUnit\Framework\TestCase;

class TaxCalculatorServiceTest extends TestCase
{
    private TaxCalculatorService $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TaxCalculatorService();
    }

    public function testSmallAmount500(): void
    {
        $result = $this->calculator->calculate(500);

        $this->assertSame(50.00, $result['courtFee']);
        $this->assertSame(29.90, $result['platformFee']);
        $this->assertSame(79.90, $result['totalFee']);
    }

    public function testBoundary2000(): void
    {
        $result = $this->calculator->calculate(2000);

        $this->assertSame(50.00, $result['courtFee']);
        $this->assertSame(29.90, $result['platformFee']);
        $this->assertSame(79.90, $result['totalFee']);
    }

    public function testJustAboveBoundary2001(): void
    {
        $result = $this->calculator->calculate(2001);

        // 250 + 2% of 1 = 250.02
        $this->assertSame(250.02, $result['courtFee']);
        $this->assertSame(29.90, $result['platformFee']);
        $this->assertSame(279.92, $result['totalFee']);
    }

    public function testMidRange5000(): void
    {
        $result = $this->calculator->calculate(5000);

        // 250 + 2% of 3000 = 250 + 60 = 310
        $this->assertSame(310.00, $result['courtFee']);
        $this->assertSame(29.90, $result['platformFee']);
        $this->assertSame(339.90, $result['totalFee']);
    }

    public function testMaximum10000(): void
    {
        $result = $this->calculator->calculate(10000);

        // 250 + 2% of 8000 = 250 + 160 = 410
        $this->assertSame(410.00, $result['courtFee']);
        $this->assertSame(29.90, $result['platformFee']);
        $this->assertSame(439.90, $result['totalFee']);
    }

    public function testInvalidAmountZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate(0);
    }

    public function testInvalidAmountNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate(-100);
    }

    public function testInvalidAmountTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate(10001);
    }
}
