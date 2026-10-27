<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\ClaimBalance;
use PHPUnit\Framework\TestCase;

final class ClaimBalanceTest extends TestCase
{
    public function testAPartialPaymentLeavesTheRest(): void
    {
        self::assertSame(600.0, ClaimBalance::outstandingRon(1000.0, 1000.0, 400.0));
    }

    public function testAPaymentInAnotherCurrencyComesOffInTheSameProportion(): void
    {
        // 250 EUR of an invoice of 1.000 EUR worth 4.975 lei: a quarter is paid.
        self::assertSame(3731.25, ClaimBalance::outstandingRon(4975.0, 1000.0, 250.0));
    }

    public function testAnOverpaymentLeavesNothingNeverLessThanNothing(): void
    {
        self::assertSame(0.0, ClaimBalance::outstandingRon(1000.0, 1000.0, 1500.0));
    }

    public function testNoPaymentOrNoTotalLeavesTheValueAsItIs(): void
    {
        self::assertSame(1000.0, ClaimBalance::outstandingRon(1000.0, 1000.0, 0.0));
        self::assertSame(1000.0, ClaimBalance::outstandingRon(1000.0, 0.0, 300.0));
    }
}
