<?php

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Enum\ExtractionStatus;
use PHPUnit\Framework\TestCase;

class DocumentEntityTest extends TestCase
{
    public function testExtractionStatusDefaultsToPending(): void
    {
        $doc = new Document();

        $this->assertSame(ExtractionStatus::PENDING, $doc->getExtractionStatus());
        $this->assertNull($doc->getExtractedData());
        $this->assertNull($doc->getExtractionConfidence());
    }

    public function testExtractionFieldsSetters(): void
    {
        $doc = new Document();

        $doc->setExtractedData(['creditor' => ['name' => 'Test'], 'confidence' => 0.85]);
        $doc->setExtractionStatus(ExtractionStatus::COMPLETED);
        $doc->setExtractionConfidence('0.85');

        $this->assertSame(['creditor' => ['name' => 'Test'], 'confidence' => 0.85], $doc->getExtractedData());
        $this->assertSame(ExtractionStatus::COMPLETED, $doc->getExtractionStatus());
        $this->assertSame('0.85', $doc->getExtractionConfidence());
    }

    public function testExtractionStrategyDefaultsToNull(): void
    {
        $doc = new Document();

        $this->assertNull($doc->getExtractionStrategy());
    }

    public function testExtractionStrategySetterAndGetter(): void
    {
        $doc = new Document();

        $doc->setExtractionStrategy('pdf_parser');

        $this->assertSame('pdf_parser', $doc->getExtractionStrategy());

        $doc->setExtractionStrategy(null);
        $this->assertNull($doc->getExtractionStrategy());
    }
}
