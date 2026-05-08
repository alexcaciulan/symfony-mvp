<?php

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\StampDutyCalculator;
use PHPUnit\Framework\TestCase;

class StampDutyCalculatorTest extends TestCase
{
    private const LAW_VERSION = 'OUG 80/2013 art. 6 alin. 2 — text aplicabil 2026-05-01';

    public function testReturnsConfiguredFixedFee(): void
    {
        $calc = new StampDutyCalculator(200.0, self::LAW_VERSION);

        $result = $calc->calculate();

        $this->assertEqualsWithDelta(200.0, $result->amount, 0.01);
    }

    public function testResultCarriesConfiguredLawVersion(): void
    {
        $calc = new StampDutyCalculator(200.0, self::LAW_VERSION);

        $result = $calc->calculate();

        $this->assertSame(self::LAW_VERSION, $result->lawVersion);
    }
}
