<?php

namespace App\Tests\Entity;

use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

class SubscriptionEntityTest extends TestCase
{
    public function testDefaults(): void
    {
        $sub = new Subscription();

        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->getStatus());
        $this->assertSame(0, $sub->getCasesConsumed());
        $this->assertNull($sub->getExternalId());
    }

    public function testGettersAndSetters(): void
    {
        $sub = new Subscription();
        $user = new User();
        $plan = new Plan();
        $start = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable('2026-02-01');

        $sub->setUser($user);
        $sub->setPlan($plan);
        $sub->setStatus(SubscriptionStatus::PAST_DUE);
        $sub->setCurrentPeriodStart($start);
        $sub->setCurrentPeriodEnd($end);
        $sub->setCasesConsumed(5);
        $sub->setExternalId('sub_123');

        $this->assertSame($user, $sub->getUser());
        $this->assertSame($plan, $sub->getPlan());
        $this->assertSame(SubscriptionStatus::PAST_DUE, $sub->getStatus());
        $this->assertSame($start, $sub->getCurrentPeriodStart());
        $this->assertSame($end, $sub->getCurrentPeriodEnd());
        $this->assertSame(5, $sub->getCasesConsumed());
        $this->assertSame('sub_123', $sub->getExternalId());
    }

    public function testIncrementCasesConsumed(): void
    {
        $sub = new Subscription();

        $sub->incrementCasesConsumed();
        $sub->incrementCasesConsumed();

        $this->assertSame(2, $sub->getCasesConsumed());
    }
}
