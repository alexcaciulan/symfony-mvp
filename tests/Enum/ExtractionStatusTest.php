<?php

namespace App\Tests\Enum;

use App\Enum\ExtractionStatus;
use PHPUnit\Framework\TestCase;

class ExtractionStatusTest extends TestCase
{
    public function testHasSixCases(): void
    {
        $this->assertCount(6, ExtractionStatus::cases());
    }

    public function testOnlySettledStatusesAreTerminal(): void
    {
        $this->assertTrue(ExtractionStatus::COMPLETED->isTerminal());
        $this->assertTrue(ExtractionStatus::FAILED->isTerminal());
        $this->assertTrue(ExtractionStatus::SKIPPED_BY_POLICY->isTerminal());

        $this->assertFalse(ExtractionStatus::PENDING->isTerminal());
        $this->assertFalse(ExtractionStatus::PROCESSING->isTerminal());
        // The queue will come back to it, so the wizard must keep waiting.
        $this->assertFalse(ExtractionStatus::PENDING_RETRY->isTerminal());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (ExtractionStatus::cases() as $case) {
            $this->assertNotEmpty($case->label());
        }
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(ExtractionStatus::PENDING, ExtractionStatus::from('PENDING'));
        $this->assertSame(ExtractionStatus::COMPLETED, ExtractionStatus::from('COMPLETED'));
        $this->assertNull(ExtractionStatus::tryFrom('NOT_A_STATUS'));
    }
}
