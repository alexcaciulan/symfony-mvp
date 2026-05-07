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

    public function testToStringReturnsName(): void
    {
        $plan = new Plan();
        $plan->setName('Starter');

        $this->assertSame('Starter', (string) $plan);
    }
}
