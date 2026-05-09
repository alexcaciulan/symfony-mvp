<?php

namespace App\Tests\Enum;

use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use PHPUnit\Framework\TestCase;

class DeadlineTypeTest extends TestCase
{
    public function testHasSixCases(): void
    {
        $this->assertCount(6, DeadlineType::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (DeadlineType::cases() as $case) {
            $this->assertNotEmpty($case->label());
        }
    }

    public function testDefaultPrioritate(): void
    {
        $this->assertSame(DeadlinePriority::CRITICAL, DeadlineType::CERERE_IN_ANULARE->defaultPrioritate());
        $this->assertSame(DeadlinePriority::CRITICAL, DeadlineType::PRESCRIPTIE->defaultPrioritate());
        $this->assertSame(DeadlinePriority::HIGH, DeadlineType::DEPUNERE_CERERE->defaultPrioritate());
        $this->assertSame(DeadlinePriority::HIGH, DeadlineType::JUDECATA->defaultPrioritate());
        $this->assertSame(DeadlinePriority::MEDIUM, DeadlineType::RASPUNS_SOMATIE->defaultPrioritate());
        $this->assertSame(DeadlinePriority::MEDIUM, DeadlineType::OTHER->defaultPrioritate());
    }
}
