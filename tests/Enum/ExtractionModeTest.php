<?php

namespace App\Tests\Enum;

use App\Enum\ExtractionMode;
use PHPUnit\Framework\TestCase;

class ExtractionModeTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        $this->assertCount(3, ExtractionMode::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (ExtractionMode::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Missing label for {$case->name}");
            $this->assertStringStartsWith('enum.extraction_mode.', $case->label());
        }
    }

    public function testIsAiAllowedReturnsFalseForLocalOnly(): void
    {
        $this->assertFalse(ExtractionMode::LOCAL_ONLY->isAiAllowed());
    }

    public function testIsAiAllowedReturnsTrueForBalancedAndMaxAccuracy(): void
    {
        $this->assertTrue(ExtractionMode::BALANCED->isAiAllowed());
        $this->assertTrue(ExtractionMode::MAX_ACCURACY->isAiAllowed());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(ExtractionMode::LOCAL_ONLY, ExtractionMode::from('LOCAL_ONLY'));
        $this->assertSame(ExtractionMode::BALANCED, ExtractionMode::from('BALANCED'));
        $this->assertNull(ExtractionMode::tryFrom('NOT_A_MODE'));
    }
}
