<?php

namespace App\Tests\Enum;

use App\Enum\ExtractionStatus;
use PHPUnit\Framework\TestCase;

class ExtractionStatusTest extends TestCase
{
    public function testHasFourCases(): void
    {
        $this->assertCount(4, ExtractionStatus::cases());
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
