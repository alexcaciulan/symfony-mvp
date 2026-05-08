<?php

namespace App\Tests\Enum;

use App\Enum\DeadlinePriority;
use PHPUnit\Framework\TestCase;

class DeadlinePriorityTest extends TestCase
{
    public function testHasFourCases(): void
    {
        $this->assertCount(4, DeadlinePriority::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (DeadlinePriority::cases() as $case) {
            $this->assertNotEmpty($case->label());
        }
    }

    public function testAllCasesHaveColors(): void
    {
        foreach (DeadlinePriority::cases() as $case) {
            $this->assertNotEmpty($case->color());
        }
    }

    public function testCriticalIsRed(): void
    {
        $this->assertSame('red', DeadlinePriority::CRITICAL->color());
    }
}
