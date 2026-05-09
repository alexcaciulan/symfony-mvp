<?php

namespace App\Tests\Service\Extraction;

use App\Entity\Document;
use App\Service\Extraction\StubExtractionStrategy;
use PHPUnit\Framework\TestCase;

class StubExtractionStrategyTest extends TestCase
{
    public function testSupportsReturnsTrueForAnyDocument(): void
    {
        $strategy = new StubExtractionStrategy();

        $this->assertTrue($strategy->supports(new Document()));
    }

    public function testPriorityIsTen(): void
    {
        $this->assertSame(10, (new StubExtractionStrategy())->priority());
        $this->assertSame(10, StubExtractionStrategy::PRIORITY);
    }

    public function testIsAiBackedReturnsFalse(): void
    {
        $this->assertFalse((new StubExtractionStrategy())->isAiBacked());
    }

    public function testExtractReturnsDtoWithNullsAndZeroConfidence(): void
    {
        $strategy = new StubExtractionStrategy();
        $document = new Document();

        $result = $strategy->extract($document);

        $this->assertSame('stub', $result->strategy);
        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertNull($result->creditor);
        $this->assertNull($result->debtor);
        $this->assertNull($result->claim);
        $this->assertNull($result->rawOcrText);
        $this->assertSame(StubExtractionStrategy::STRATEGY_KEY, $result->strategy);
    }
}
