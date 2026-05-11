<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\DataExtractedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Pas 3.0 wizard step 0 subscriber that pushes per-document extraction status
 * updates to the Mercure hub. The browser side opens an EventSource against
 * `document/{id}/extraction-status` for each document the user uploaded and
 * swaps the status badge / card content the moment the worker finishes.
 *
 * Payload is metadata-only — `{documentId, status, confidence}`. We deliberately
 * do NOT include `extractedData` in the Mercure stream — addresses W1 GDPR raised
 * in the Pas 2.6 legal review (extractedData contains CNP / IBAN / addresses).
 *
 * Update is marked `private: true` so the hub requires a subscriber JWT with the
 * topic in `mercure.subscribe` before delivering. The compose.yaml hub runs with
 * `anonymous` enabled (low-friction dev), but `private: true` still gates access
 * by JWT subscriber claims — addresses W1 (existence-disclosure on sequential
 * Document.id) raised in the Pas 3.0 legal review. The frontend Mercure
 * controller picks the subscriber JWT off `window.MERCURE_PUBLIC_URL`-adjacent
 * cookie set by Symfony's `mercure.discovery_link` and authenticates per-topic.
 *
 * Mercure failures are swallowed — the extraction itself has already been
 * persisted by the message handler, so the user can still proceed with the
 * wizard (the polling fallback Stimulus controller will pick up the update).
 * Re-throwing here would mark the message as failed and trigger an unnecessary
 * retry of the whole cascade.
 */
final class ExtractionMercurePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[AsEventListener(event: DataExtractedEvent::class)]
    public function onDataExtracted(DataExtractedEvent $event): void
    {
        $document = $event->document;

        $confidenceRaw = $document->getExtractionConfidence();
        $payload = [
            'documentId' => $document->getId(),
            'status' => $document->getExtractionStatus()->value,
            'confidence' => $confidenceRaw === null ? 0.0 : (float) $confidenceRaw,
        ];

        // Topic scoped to the uploader so the subscriber JWT (which carries a
        // `user/{userId}/document/*/extraction-status` wildcard) only matches
        // updates that belong to this user. Even with hub `anonymous` enabled,
        // `private: true` requires the JWT subscriber-claim and the topic-URI
        // includes the owner — no cross-user leakage.
        $uploaderId = $document->getUploadedBy()->getId();
        $topic = sprintf('user/%d/document/%d/extraction-status', $uploaderId, $document->getId());

        try {
            $this->hub->publish(new Update(
                topics: $topic,
                data: json_encode($payload, JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $e) {
            // Hub down / network blip / JSON encoding failure. The polling
            // fallback controller will reconcile the UI from the wizard
            // status endpoint within ~3s.
            $this->logger->warning('mercure.extraction_status.publish_failed', [
                'documentId' => $document->getId(),
                'topic' => $topic,
                'exceptionClass' => $e::class,
            ]);
        }
    }
}
