<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;

/**
 * Null Object fallback strategy.
 *
 * Always supports any document and always returns a valid
 * {@see ExtractedDocumentData} with no extracted fields and
 * `globalConfidence = 0.0`. Guarantees that the cascade in
 * {@see DataExtractionService} never falls off the end without
 * a result — no null handling needed in callers.
 */
final class StubExtractionStrategy implements ExtractionStrategyInterface
{
    public const STRATEGY_KEY = 'stub';

    public const PRIORITY = 10;

    public function supports(Document $document): bool
    {
        return true;
    }

    public function extract(Document $document): ExtractedDocumentData
    {
        return new ExtractedDocumentData(
            sourceDocumentId: (int) $document->getId(),
            strategy: self::STRATEGY_KEY,
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable(),
        );
    }

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function isAiBacked(): bool
    {
        return false;
    }
}
