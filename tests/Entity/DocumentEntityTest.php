<?php

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
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

    /**
     * All four fields are nullable on purpose: rows written before they existed
     * have none, and reading them must not require a backfill.
     */
    public function testNewDiagnosticFieldsDefaultToNull(): void
    {
        $doc = new Document();

        $this->assertNull($doc->getContentHash());
        $this->assertNull($doc->getDetectedType());
        $this->assertNull($doc->getDetectedTypeConfidence());
        $this->assertNull($doc->getExtractionFailureReason());
    }

    public function testContentHashSetterAndGetter(): void
    {
        $doc = new Document();
        $hash = hash('sha256', 'contents of an invoice pdf');

        $doc->setContentHash($hash);
        $this->assertSame($hash, $doc->getContentHash());
        $this->assertSame(64, strlen($doc->getContentHash()), 'The column is sized for a sha256 hex digest');

        $doc->setContentHash(null);
        $this->assertNull($doc->getContentHash());
    }

    /**
     * detectedType is deliberately separate from the user-chosen type: keeping
     * both is what makes an automatic promotion auditable afterwards.
     */
    public function testDetectedTypeIsIndependentOfTheUserChosenType(): void
    {
        $doc = new Document();
        $doc->setDocumentType(DocumentType::ALT_DOCUMENT);

        $doc->setDetectedType(DocumentType::FACTURA);
        $doc->setDetectedTypeConfidence('0.82');

        $this->assertSame(DocumentType::FACTURA, $doc->getDetectedType());
        $this->assertSame('0.82', $doc->getDetectedTypeConfidence());
        $this->assertSame(DocumentType::ALT_DOCUMENT, $doc->getDocumentType());
    }

    public function testExtractionFailureReasonSetterAndGetter(): void
    {
        $doc = new Document();

        $doc->setExtractionFailureReason(ExtractionFailureReason::FILE_TOO_LARGE);
        $this->assertSame(ExtractionFailureReason::FILE_TOO_LARGE, $doc->getExtractionFailureReason());

        // Cleared when a retry is queued, so a stale cause never outlives it.
        $doc->setExtractionFailureReason(null);
        $this->assertNull($doc->getExtractionFailureReason());
    }
}
