<?php

namespace App\Tests\Entity;

use App\Entity\Plan;
use PHPUnit\Framework\TestCase;

class PlanEntityTest extends TestCase
{
    public function testIsActiveDefaultsTrue(): void
    {
        $plan = new Plan();

        $this->assertTrue($plan->isActive());
    }

    public function testGettersAndSetters(): void
    {
        $plan = new Plan();

        $plan->setName('Pro');
        $plan->setPriceMonthly('99.00');
        $plan->setIncludedCases(10);
        $plan->setPricePerExtra('15.00');
        $plan->setIsActive(false);

        $this->assertSame('Pro', $plan->getName());
        $this->assertSame('99.00', $plan->getPriceMonthly());
        $this->assertSame(10, $plan->getIncludedCases());
        $this->assertSame('15.00', $plan->getPricePerExtra());
        $this->assertFalse($plan->isActive());
    }

    public function testComparePriceToOrdersPlans(): void
    {
        $starter = (new Plan())->setPriceMonthly('99.00');
        $pro = (new Plan())->setPriceMonthly('299.00');

        $this->assertGreaterThan(0, $pro->comparePriceTo($starter));
        $this->assertLessThan(0, $starter->comparePriceTo($pro));
        $this->assertSame(0, $starter->comparePriceTo((new Plan())->setPriceMonthly('99.00')));
    }

    public function testComparePriceToIsExactOnCentDifferences(): void
    {
        // Integer bani, so a one-ban gap is never rounded away into "same price",
        // which would silently turn an upgrade into a scheduled change.
        $a = (new Plan())->setPriceMonthly('99.01');
        $b = (new Plan())->setPriceMonthly('99.00');

        $this->assertGreaterThan(0, $a->comparePriceTo($b));
    }

    public function testToStringReturnsName(): void
    {
        $plan = new Plan();
        $plan->setName('Starter');

        $this->assertSame('Starter', (string) $plan);
    }
}
