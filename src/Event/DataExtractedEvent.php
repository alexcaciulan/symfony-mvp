<?php

namespace App\Event;

use App\Entity\Document;

/**
 * Domain event fired by {@see App\MessageHandler\ExtractDataMessageHandler}
 * after a Document's extraction cascade has completed (regardless of
 * whether the result was COMPLETED with usable data or FAILED with zero
 * confidence — the extraction lifecycle has reached a terminal state).
 *
 * Pas 3.0 wizard will register an EventSubscriber that publishes a Mercure
 * update on `case/{caseId}/extraction-status` so the UI can replace the
 * document row via Turbo Stream without polling. Pas 2.6 itself dispatches
 * the event but ships no listener — early consumers can subscribe later
 * without changing the handler.
 */
final readonly class DataExtractedEvent
{
    public function __construct(public Document $document) {}
}
