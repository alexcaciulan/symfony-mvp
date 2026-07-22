<?php

declare(strict_types=1);

namespace App\Tests\DTO\Extraction;

use App\DTO\Extraction\DocumentClassification;
use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

/**
 * The classification round-trips through the persisted JSON payload, so a
 * reader must survive anything an older or malformed payload contains: this is
 * the value the wizard shows next to the type the lawyer chose.
 */
final class DocumentClassificationTest extends TestCase
{
    public function testItRoundTripsThroughThePersistedShape(): void
    {
        $original = new DocumentClassification(DocumentType::FACTURA, 0.93, 'factură storno', 'antet');

        $restored = DocumentClassification::fromArray($original->toArray());

        $this->assertNotNull($restored);
        $this->assertSame(DocumentType::FACTURA, $restored->type);
        $this->assertSame(0.93, $restored->confidence);
        $this->assertSame('factură storno', $restored->subtype);
        $this->assertSame('antet', $restored->rationale);
    }

    public function testAPayloadWithoutAClassificationReadsAsNone(): void
    {
        $this->assertNull(DocumentClassification::fromArray(null));
        $this->assertNull(DocumentClassification::fromArray([]));
        $this->assertNull(DocumentClassification::fromArray('factura'));
    }

    public function testAnUnknownTypeReadsAsNoneRatherThanThrowing(): void
    {
        $this->assertNull(DocumentClassification::fromArray(['type' => 'bon_fiscal', 'confidence' => 0.9]));
    }

    public function testAMissingScoreReadsAsNoConfidence(): void
    {
        $restored = DocumentClassification::fromArray(['type' => 'contract']);

        $this->assertNotNull($restored);
        $this->assertSame(0.0, $restored->confidence);
    }

    public function testTheScoreIsClampedOnRead(): void
    {
        $this->assertSame(1.0, DocumentClassification::fromArray(['type' => 'contract', 'confidence' => 4])->confidence);
        $this->assertSame(0.0, DocumentClassification::fromArray(['type' => 'contract', 'confidence' => -1])->confidence);
    }

    public function testEmptyTextFieldsReadAsAbsent(): void
    {
        $restored = DocumentClassification::fromArray([
            'type' => 'contract',
            'confidence' => 0.5,
            'subtype' => '',
            'rationale' => '',
        ]);

        $this->assertNull($restored->subtype);
        $this->assertNull($restored->rationale);
    }
}
