<?php

namespace App\Tests\Enum;

use App\Enum\ExtractionPipeline;
use PHPUnit\Framework\TestCase;

class ExtractionPipelineTest extends TestCase
{
    public function testHasTwoCases(): void
    {
        $this->assertCount(2, ExtractionPipeline::cases());
    }

    public function testValuesMatchWhatTheMigrationWrites(): void
    {
        // The migration pins existing rows with a literal string; a rename here
        // without one there would silently break those accounts.
        $this->assertSame('LEGACY_CASCADE', ExtractionPipeline::LEGACY_CASCADE->value);
        $this->assertSame('AI_ONLY', ExtractionPipeline::AI_ONLY->value);
    }

    public function testLabelsFollowTheEnumKeyConvention(): void
    {
        foreach (ExtractionPipeline::cases() as $case) {
            $this->assertSame('enum.extraction_pipeline.' . $case->value, $case->label());
        }
    }
}
