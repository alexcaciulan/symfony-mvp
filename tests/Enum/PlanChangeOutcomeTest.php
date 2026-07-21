<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\PlanChangeOutcome;
use PHPUnit\Framework\TestCase;

class PlanChangeOutcomeTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        $this->assertCount(3, PlanChangeOutcome::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (PlanChangeOutcome::cases() as $case) {
            $this->assertStringStartsWith('enum.plan_change_outcome.', $case->label());
        }
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(PlanChangeOutcome::UPGRADE_PENDING_PAYMENT, PlanChangeOutcome::from('upgrade_pending_payment'));
        $this->assertSame(PlanChangeOutcome::DOWNGRADE_SCHEDULED, PlanChangeOutcome::from('downgrade_scheduled'));
        $this->assertNull(PlanChangeOutcome::tryFrom('nope'));
    }
}
