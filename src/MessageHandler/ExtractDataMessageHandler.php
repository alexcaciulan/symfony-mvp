<?php

namespace App\MessageHandler;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
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
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

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
 *   2. Failure containment, split by cause: re-throwing from a Messenger
 *      handler triggers Symfony's retry strategy and eventually parks the
 *      message in the `failed` transport. For a corrupt PDF or a malformed AI
 *      response that's the wrong shape: they fail identically on every retry,
 *      consume rate-limit budget, and clutter the failed queue, so those are
 *      left in a terminal state (FAILED), logged, and ACKed. Operator
 *      reconciliation is a manual workflow. A transient cause is different:
 *      when the strategy reports one (see
 *      {@see \App\Enum\ExtractionFailureReason::isTransient()}) the document is
 *      moved to PENDING_RETRY and the message is re-thrown as recoverable.
 *
 *      Note: B1 fix in commit 8cab4ab made `DataExtractionService::extract()`
 *      itself catch \Throwable per-strategy, so the handler-level catch is
 *      defense in depth — primarily for flush() failures (DB constraint
 *      violations, lost connections during the cascade).
 *
 *   3. Downstream notification: dispatches `DataExtractedEvent` so Pas 3.0
 *      can publish Mercure updates without coupling the handler to the
 *      transport layer. The event fires on BOTH terminal outcomes — success
 *      (COMPLETED) and caught failure (FAILED) — so subscribers see every
 *      transition out of PROCESSING. Subscribers inspect
 *      `$document->getExtractionStatus()` to branch behaviour. If the
 *      failure-path flush itself failed (Document stuck in PROCESSING),
 *      we skip the event because the persisted state doesn't match what
 *      we'd announce — operator reconciliation handles that edge.
 */
#[AsMessageHandler]
final class ExtractDataMessageHandler
{
    /** Fifteen minutes: enough for a per-minute or per-day window to move. */
    private const RATE_LIMIT_RETRY_DELAY_MS = 900_000;

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

        // Flag that drives whether to dispatch DataExtractedEvent at the end.
        // Stays true on success and on caught failure where we successfully
        // flushed FAILED; flips to false only when the failure-path flush also
        // blew up (Document is stuck in PROCESSING — don't announce a state
        // we couldn't persist).
        $shouldAnnounce = true;

        try {
            // The orchestrator sets COMPLETED / FAILED + extractedData via
            // persistResult() but does not flush — we own that here so the
            // status transition is atomic with the data payload.
            $result = $this->extractor->extract($document);

            // A transient cause (provider down, quota spent) is worth another
            // attempt, so hand the message back to Messenger. Flip the status
            // first: the orchestrator has already written FAILED, and leaving
            // it there would show the lawyer a failure that the queue is still
            // working on, then silently turn into COMPLETED.
            $reason = $result->failureReason;
            if ($reason !== null && $reason->isTransient()) {
                $document->setExtractionStatus(ExtractionStatus::PENDING_RETRY);
                $this->em->flush();
                $this->logger->warning('extraction.handler.transient_failure_retrying', [
                    'documentId' => $message->documentId,
                    'reason' => $reason->value,
                ]);
                // Announce before re-throwing, otherwise the badge stays on
                // PROCESSING until the retry lands.
                $this->events->dispatch(new DataExtractedEvent($document));

                throw new RecoverableMessageHandlingException(
                    sprintf(
                        'Transient extraction failure (%s) for document %d',
                        $reason->value,
                        $message->documentId,
                    ),
                    // An exhausted quota does not refill in the seconds the
                    // default exponential backoff waits, so retrying that fast
                    // just spends the remaining attempts for nothing.
                    retryDelay: $reason === ExtractionFailureReason::RATE_LIMIT_EXCEEDED
                        ? self::RATE_LIMIT_RETRY_DELAY_MS
                        : null,
                );
            }

            $this->promoteDetectedType($document, $result);

            $this->em->flush();
        } catch (RecoverableMessageHandlingException $e) {
            // Our own retry signal, which Messenger must see. Catching it in the
            // \Throwable arm below would turn every retry into a silent ACK.
            throw $e;
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
                // alerting can fire. Skip event dispatch because the announced
                // state would diverge from the persisted state.
                $this->logger->critical('extraction.handler.flush_failed_in_failure_path', [
                    'documentId' => $message->documentId,
                    'flushExceptionClass' => $flushError::class,
                ]);
                $shouldAnnounce = false;
            }

            // Deliberately swallow — Pas 2.6 spec: "NU re-throw (dă chance la
            // fallback manual)". The user completes manually in the wizard
            // when they see status=FAILED.
        }

        // Announce terminal status (COMPLETED or FAILED) so the Pas 3.0
        // Mercure publisher subscriber can push the badge update to the UI.
        // Subscribers inspect $document->getExtractionStatus() to branch.
        if ($shouldAnnounce) {
            $this->events->dispatch(new DataExtractedEvent($document));
        }
    }

    /**
     * Adopts the detected type as the document's own, under two conditions.
     *
     * The detection has to be confident, because a wrong type sends the next
     * extraction into the wrong specialised prompt, and the document has to
     * still be unclassified by the lawyer: a type someone chose is a decision,
     * and a model that overrode it would silently relabel evidence. Both values
     * stay on the document either way, so the two remain distinguishable when
     * someone asks where a filing's data came from.
     *
     * A type the platform generates itself is refused outright. Nothing in the
     * type system stops a classification from carrying one, and this is the
     * step that writes, so this is where the line has to hold: a piece of
     * evidence relabelled as a filing would be packaged as one.
     */
    private function promoteDetectedType(Document $document, ExtractedDocumentData $result): void
    {
        $classification = $result->classification;
        if ($classification === null
            || !$classification->isActionable()
            || $classification->type->isAutoGenerated()
            || $document->getDocumentType() !== DocumentType::ALT_DOCUMENT) {
            return;
        }

        $document->setDocumentType($classification->type);
        $this->logger->info('extraction.handler.detected_type_promoted', [
            'documentId' => $document->getId(),
            'detectedType' => $classification->type->value,
            'confidence' => $classification->confidence,
        ]);
    }
}
