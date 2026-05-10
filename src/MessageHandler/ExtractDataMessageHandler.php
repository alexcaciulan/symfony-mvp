<?php

namespace App\MessageHandler;

use App\Enum\ExtractionStatus;
use App\Event\DataExtractedEvent;
use App\Message\ExtractDataMessage;
use App\Repository\DocumentRepository;
use App\Service\Extraction\DataExtractionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async handler for {@see ExtractDataMessage}. Wraps the extraction cascade
 * in three concerns the synchronous orchestrator can't own on its own:
 *
 *   1. Status lifecycle: marks the Document as PROCESSING and flushes BEFORE
 *      running the cascade so concurrent UI polling / Mercure subscribers
 *      (Pas 3.0) observe the in-flight state instead of jumping
 *      PENDING → COMPLETED with no intermediate. The orchestrator only sets
 *      the terminal status (COMPLETED / FAILED) via persistResult() and does
 *      NOT flush — that's by design (its docblock says caller decides
 *      lifecycle), so this handler owns the flush(es).
 *
 *   2. Failure containment: re-throwing from a Messenger handler triggers
 *      Symfony's retry strategy (3 attempts × exponential backoff) and
 *      eventually parks the message in the `failed` transport. For
 *      extraction failures that's the wrong shape — a corrupt PDF or a
 *      down-graded AI response will fail identically on every retry,
 *      consume rate-limit budget, and clutter the failed queue. We catch
 *      everything, leave the Document in a terminal state (FAILED), log,
 *      and ACK. Operator reconciliation is a manual workflow.
 *
 *      Note: B1 fix in commit 8cab4ab made `DataExtractionService::extract()`
 *      itself catch \Throwable per-strategy, so the handler-level catch is
 *      defense in depth — primarily for flush() failures (DB constraint
 *      violations, lost connections during the cascade).
 *
 *   3. Downstream notification: dispatches `DataExtractedEvent` so Pas 3.0
 *      can publish Mercure updates without coupling the handler to the
 *      transport layer. Pas 2.6 ships no listener — early consumers will
 *      subscribe later.
 */
#[AsMessageHandler]
final class ExtractDataMessageHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DataExtractionService $extractor,
        private readonly EventDispatcherInterface $events,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(ExtractDataMessage $message): void
    {
        $document = $this->documents->find($message->documentId);
        if ($document === null) {
            // Orphan message — Document deleted between dispatch and consume.
            // ACK (return normally) so Messenger doesn't retry; log for ops
            // visibility in case it indicates an unexpected delete pattern.
            $this->logger->warning('extraction.handler.document_not_found', [
                'documentId' => $message->documentId,
            ]);

            return;
        }

        $document->setExtractionStatus(ExtractionStatus::PROCESSING);
        $this->em->flush();

        try {
            // The orchestrator sets COMPLETED / FAILED + extractedData via
            // persistResult() but does not flush — we own that here so the
            // status transition is atomic with the data payload.
            $this->extractor->extract($document);
            $this->em->flush();

            $this->events->dispatch(new DataExtractedEvent($document));
        } catch (\Throwable $e) {
            $this->logger->error('extraction.handler.unexpected_failure', [
                'documentId' => $message->documentId,
                'exceptionClass' => $e::class,
                'code' => $e->getCode(),
            ]);

            $document->setExtractionStatus(ExtractionStatus::FAILED);
            try {
                $this->em->flush();
            } catch (\Throwable $flushError) {
                // Both the cascade AND the failure-state flush blew up. The
                // Document stays in PROCESSING; ops will reconcile via a
                // follow-up command (out of scope MVP). Log critical so
                // alerting can fire.
                $this->logger->critical('extraction.handler.flush_failed_in_failure_path', [
                    'documentId' => $message->documentId,
                    'flushExceptionClass' => $flushError::class,
                ]);
            }

            // Deliberately swallow — Pas 2.6 spec: "NU re-throw (dă chance la
            // fallback manual)". The user completes manually in the wizard
            // when they see status=FAILED.
        }
    }
}
