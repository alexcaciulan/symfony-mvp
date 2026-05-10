<?php

namespace App\Message;

/**
 * Async message dispatched after a Document is uploaded so its extraction
 * cascade ({@see App\Service\Extraction\DataExtractionService}) runs in a
 * background worker rather than blocking the HTTP request — extractions can
 * take 5-30 seconds end-to-end (Tesseract on multipage scans + Anthropic
 * round-trip), well past any reasonable response budget.
 *
 * Carries only the document id by design. The handler reloads the Document
 * via DocumentRepository::find() at consume time so it operates on a fresh
 * managed entity, AFTER the caller's transaction has been committed
 * (Doctrine transport's `dispatch_after_current_bus` middleware in Symfony 7
 * guarantees the ordering — the message is published only post-commit).
 *
 * Caller (Pas 3.0 wizard upload controller):
 *   $bus->dispatch(new ExtractDataMessage($document->getId()));
 */
final readonly class ExtractDataMessage
{
    public function __construct(public int $documentId) {}
}
