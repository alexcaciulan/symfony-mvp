<?php

namespace App\Tests\MessageHandler;

use App\DTO\Extraction\ExtractedDocumentData;
use App\DTO\Extraction\DocumentClassification;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionStatus;
use App\Event\DataExtractedEvent;
use App\Message\ExtractDataMessage;
use App\MessageHandler\ExtractDataMessageHandler;
use App\Repository\DocumentRepository;
use App\Service\Extraction\DataExtractionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Nivel 1 — handler logic in isolation. The cascade itself is mocked because
 * it has its own exhaustive coverage at Pas 2.5.x; here we only verify the
 * orchestration concerns the handler owns:
 *   - PROCESSING flush BEFORE the cascade runs (so concurrent observers see
 *     the in-flight state)
 *   - terminal flush AFTER the cascade
 *   - DataExtractedEvent dispatched on success only
 *   - throwable in the cascade or in flush() → FAILED + log + ACK (no rethrow)
 *   - orphan message (Document deleted between dispatch and consume) → ACK
 *   - flush failure inside the failure path → critical log + ACK
 */
#[AllowMockObjectsWithoutExpectations]
class ExtractDataMessageHandlerTest extends TestCase
{
    public function testOrphanMessageAcksAndLogsWithoutTouchingTheCascade(): void
    {
        $documents = $this->createMock(DocumentRepository::class);
        $documents->expects(self::once())
            ->method('find')
            ->with(404)
            ->willReturn(null);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->expects(self::never())->method('extract');

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::never())->method('dispatch');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $handler = new ExtractDataMessageHandler($documents, $extractor, $events, $em, new NullLogger());

        // ACK is "return normally" — no exception means Messenger ACKs the message.
        $handler(new ExtractDataMessage(404));
    }

    public function testHappyPathFlushesProcessingBeforeCascadeAndDispatchesEvent(): void
    {
        $document = $this->makeDocument(7);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->with(7)->willReturn($document);

        // We need to assert ORDER: PROCESSING flush happens BEFORE extract().
        // PHPUnit's expectation engine doesn't naturally express ordering across
        // mocks, so we capture observations into an ordered log.
        $callOrder = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(function () use (&$callOrder, $document): void {
            $callOrder[] = ['flush', $document->getExtractionStatus()];
        });

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->expects(self::once())
            ->method('extract')
            ->with($document)
            ->willReturnCallback(function (Document $doc) use (&$callOrder): ExtractedDocumentData {
                $callOrder[] = ['extract', $doc->getExtractionStatus()];
                // Simulate orchestrator's persistResult() — sets status to COMPLETED.
                $doc->setExtractionStatus(ExtractionStatus::COMPLETED);

                return new ExtractedDocumentData(
                    sourceDocumentId: 7,
                    strategy: 'pdf_parser',
                    globalConfidence: 0.9,
                    extractedAt: new \DateTimeImmutable(),
                );
            });

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(DataExtractedEvent::class));

        $handler = new ExtractDataMessageHandler($documents, $extractor, $events, $em, new NullLogger());
        $handler(new ExtractDataMessage(7));

        // Sequence: flush(PROCESSING) → extract(PROCESSING) → flush(COMPLETED)
        $this->assertSame(
            [
                ['flush', ExtractionStatus::PROCESSING],
                ['extract', ExtractionStatus::PROCESSING],
                ['flush', ExtractionStatus::COMPLETED],
            ],
            $callOrder,
            'Handler must flush PROCESSING before invoking the cascade so concurrent observers see the in-flight state',
        );
    }

    public function testCascadeThrowableSetsFailedFlushesAndStillDispatchesEvent(): void
    {
        // Pas 3.0 OP4: DataExtractedEvent now fires on BOTH terminal outcomes
        // (success and caught failure) so the Mercure publisher can push the
        // FAILED badge to the wizard UI. The handler still swallows the
        // exception so Messenger doesn't retry.
        $document = $this->makeDocument(8);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willThrowException(new \RuntimeException('cascade exploded'));

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                fn (DataExtractedEvent $e) => $e->document === $document
                    && $e->document->getExtractionStatus() === ExtractionStatus::FAILED,
            ));

        $em = $this->createMock(EntityManagerInterface::class);
        // Two flush() calls expected: one to publish PROCESSING, one to publish FAILED.
        $em->expects(self::exactly(2))->method('flush');

        $handler = new ExtractDataMessageHandler($documents, $extractor, $events, $em, new NullLogger());

        // The whole point: NO exception escapes the handler. If it did, Messenger
        // would retry × 3 (Symfony default) and clog the failed transport with
        // identically-failing messages.
        $handler(new ExtractDataMessage(8));

        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
    }

    public function testFlushFailureInTheFailurePathStillAcks(): void
    {
        // Pathological scenario: the cascade throws AND the subsequent flush()
        // (to mark FAILED) also throws — DB connection lost in the middle of
        // a worker run. Handler must still ACK so the message doesn't loop.
        // The Document remains stuck in PROCESSING; ops reconciles via a
        // follow-up command (out of scope MVP).
        $document = $this->makeDocument(9);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willThrowException(new \RuntimeException('cascade exploded'));

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::never())->method('dispatch');

        $em = $this->createMock(EntityManagerInterface::class);
        $flushCalls = 0;
        $em->method('flush')->willReturnCallback(function () use (&$flushCalls): void {
            $flushCalls++;
            if ($flushCalls === 1) {
                // First flush (PROCESSING) succeeds.
                return;
            }
            // Second flush (FAILED) blows up.
            throw new \RuntimeException('DB connection lost');
        });

        $handler = new ExtractDataMessageHandler($documents, $extractor, $events, $em, new NullLogger());

        // No exception escapes — the catch-around-flush in the handler swallows
        // the second throwable too.
        $handler(new ExtractDataMessage(9));

        $this->assertSame(2, $flushCalls);
    }

    public function testHandlerIsResolvedAsMessengerHandler(): void
    {
        // Sanity: the #[AsMessageHandler] attribute is the only thing wiring
        // this class to the bus. If anyone accidentally removes it during a
        // refactor, the handler silently stops being invoked. Reflect on the
        // attribute presence so the test fails loudly if that happens.
        $reflection = new \ReflectionClass(ExtractDataMessageHandler::class);
        $attributes = $reflection->getAttributes();
        $names = array_map(static fn ($attr) => $attr->getName(), $attributes);

        $this->assertContains(
            'Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler',
            $names,
            'ExtractDataMessageHandler must carry #[AsMessageHandler] or it will not be auto-wired to the bus',
        );
    }

    public function testProcessingFlushFailureIsRethrownToTriggerMessengerRetry(): void
    {
        // Distinct from `testFlushFailureInTheFailurePathStillAcks`: there the
        // SECOND flush (FAILED) explodes after the cascade has already failed,
        // and we want to ACK regardless. Here the FIRST flush (PROCESSING)
        // explodes — typically a DB-down-at-the-very-start scenario. That's
        // a transient infrastructure failure where Messenger's retry is the
        // RIGHT behaviour: the next attempt may succeed against a recovered
        // DB. So the handler MUST let this throw escape, NOT swallow.
        //
        // Important contrast with the catch-block in extract(): the current
        // implementation only catches \Throwable from `extract()` and from
        // the second flush. The first flush sits OUTSIDE the try/catch — its
        // exception propagates to Messenger, which re-queues with backoff.
        $document = $this->makeDocument(11);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->expects(self::never())->method('extract');

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::never())->method('dispatch');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('flush')
            ->willThrowException(new \RuntimeException('DB unreachable on initial PROCESSING flush'));

        $handler = new ExtractDataMessageHandler($documents, $extractor, $events, $em, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DB unreachable on initial PROCESSING flush/');

        // Exception propagates → Messenger sees a thrown handler → retry path.
        $handler(new ExtractDataMessage(11));
    }

    /**
     * The status flip has to happen before the re-throw, otherwise the lawyer
     * sees FAILED for as long as the message sits in the retry queue and then
     * an unexplained jump to COMPLETED.
     */
    public function testTransientFailureParksOnPendingRetryAndRethrowsForMessenger(): void
    {
        $document = $this->makeDocument(11);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->with(11)->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willReturnCallback(
            function (Document $doc): ExtractedDocumentData {
                // What the orchestrator persists when the provider is down.
                $doc->setExtractionStatus(ExtractionStatus::FAILED);

                return new ExtractedDocumentData(
                    sourceDocumentId: 11,
                    strategy: 'ai_vision',
                    globalConfidence: 0.0,
                    extractedAt: new \DateTimeImmutable(),
                    failureReason: ExtractionFailureReason::API_UNAVAILABLE,
                );
            },
        );

        $statusesAtFlush = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(function () use (&$statusesAtFlush, $document): void {
            $statusesAtFlush[] = $document->getExtractionStatus();
        });

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())->method('dispatch');

        $handler = new ExtractDataMessageHandler($documents, $extractor, $events, $em, new NullLogger());

        try {
            $handler(new ExtractDataMessage(11));
            self::fail('A transient failure must be re-thrown so Messenger retries it');
        } catch (RecoverableMessageHandlingException) {
            // expected
        }

        self::assertSame(ExtractionStatus::PENDING_RETRY, $document->getExtractionStatus());
        self::assertSame(
            [ExtractionStatus::PROCESSING, ExtractionStatus::PENDING_RETRY],
            $statusesAtFlush,
            'PENDING_RETRY must be flushed before the re-throw',
        );
    }

    public function testPermanentFailureStaysFailedAndIsAcked(): void
    {
        $document = $this->makeDocument(12);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->with(12)->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willReturnCallback(
            function (Document $doc): ExtractedDocumentData {
                $doc->setExtractionStatus(ExtractionStatus::FAILED);

                return new ExtractedDocumentData(
                    sourceDocumentId: 12,
                    strategy: 'ai_vision',
                    globalConfidence: 0.0,
                    extractedAt: new \DateTimeImmutable(),
                    failureReason: ExtractionFailureReason::FILE_TOO_LARGE,
                );
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())->method('dispatch');

        $handler = new ExtractDataMessageHandler($documents, $extractor, $events, $em, new NullLogger());

        // Returning normally is the ACK: retrying an oversized file would fail
        // identically and cost another call.
        $handler(new ExtractDataMessage(12));

        self::assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
    }

    /**
     * The retry decision reads {@see ExtractionFailureReason::isTransient()},
     * so cover every case: a reason added later without a matching branch here
     * would otherwise inherit whichever behaviour the default arm happens to
     * give it, and the wrong default is expensive in both directions (a burnt
     * budget on permanent failures, a silently dropped document on transient
     * ones).
     */
    #[DataProvider('failureReasonProvider')]
    public function testRetryDecisionFollowsTheTransientFlagForEveryReason(
        ExtractionFailureReason $reason,
        ExtractionStatus $persistedByOrchestrator,
    ): void {
        $document = $this->makeDocument(21);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->with(21)->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willReturnCallback(
            function (Document $doc) use ($reason, $persistedByOrchestrator): ExtractedDocumentData {
                $doc->setExtractionStatus($persistedByOrchestrator);

                return new ExtractedDocumentData(
                    sourceDocumentId: 21,
                    strategy: 'ai_vision',
                    globalConfidence: 0.0,
                    extractedAt: new \DateTimeImmutable(),
                    failureReason: $reason,
                );
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())->method('dispatch');

        $handler = new ExtractDataMessageHandler($documents, $extractor, $events, $em, new NullLogger());

        $rethrown = false;
        try {
            $handler(new ExtractDataMessage(21));
        } catch (RecoverableMessageHandlingException) {
            $rethrown = true;
        }

        self::assertSame($reason->isTransient(), $rethrown);
        self::assertSame(
            $reason->isTransient() ? ExtractionStatus::PENDING_RETRY : $persistedByOrchestrator,
            $document->getExtractionStatus(),
        );
    }

    /**
     * @return array<string, array{ExtractionFailureReason, ExtractionStatus}>
     */
    public static function failureReasonProvider(): array
    {
        $cases = [];
        foreach (ExtractionFailureReason::cases() as $reason) {
            // A policy skip is the one non-failure in the list: the orchestrator
            // writes SKIPPED_BY_POLICY, and the handler must leave it alone
            // rather than turn a settings choice into a malfunction.
            $cases[$reason->value] = [
                $reason,
                in_array($reason, [
                    ExtractionFailureReason::LOCAL_ONLY_MODE,
                    ExtractionFailureReason::AGREEMENT_MISSING,
                ], true)
                    ? ExtractionStatus::SKIPPED_BY_POLICY
                    : ExtractionStatus::FAILED,
            ];
        }

        return $cases;
    }

    /**
     * An exhausted quota does not refill within the seconds the default backoff
     * waits, so the rate-limit retry carries its own long delay. Without it the
     * three attempts are spent inside ten seconds and the document lands in the
     * failed transport while the budget is still empty.
     */
    public function testARateLimitRetryAsksForALongDelayInsteadOfTheDefaultBackoff(): void
    {
        $document = $this->makeDocument(31);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->with(31)->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willReturn(new ExtractedDocumentData(
            sourceDocumentId: 31,
            strategy: 'ai_vision',
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable(),
            failureReason: ExtractionFailureReason::RATE_LIMIT_EXCEEDED,
        ));

        $handler = new ExtractDataMessageHandler(
            $documents,
            $extractor,
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        try {
            $handler(new ExtractDataMessage(31));
            self::fail('A rate-limit failure must be re-thrown for retry');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertGreaterThanOrEqual(600_000, $e->getRetryDelay());
        }
    }

    /**
     * The other transient cause keeps the default backoff: a provider outage
     * can clear in seconds, and waiting a quarter of an hour would be a
     * needlessly slow wizard.
     */
    public function testAProviderOutageKeepsTheDefaultBackoff(): void
    {
        $document = $this->makeDocument(32);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->with(32)->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willReturn(new ExtractedDocumentData(
            sourceDocumentId: 32,
            strategy: 'ai_vision',
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable(),
            failureReason: ExtractionFailureReason::API_UNAVAILABLE,
        ));

        $handler = new ExtractDataMessageHandler(
            $documents,
            $extractor,
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        try {
            $handler(new ExtractDataMessage(32));
            self::fail('A transient failure must be re-thrown for retry');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertNull($e->getRetryDelay());
        }
    }

    private function makeDocument(int $id): Document
    {
        $document = new Document();
        // What the wizard stores when the lawyer did not declare a type.
        $document->setDocumentType(DocumentType::ALT_DOCUMENT);
        // Force an id via reflection — Document::getId() returns ?int and the
        // handler keys its log messages on it.
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, $id);

        return $document;
    }

    // ---------- detected type promotion ----------

    /**
     * @param ?DocumentClassification $classification what the extractor reported
     */
    private function runWithClassification(
        Document $document,
        ?DocumentClassification $classification,
    ): void {
        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willReturn(new ExtractedDocumentData(
            sourceDocumentId: (int) $document->getId(),
            strategy: 'ai_vision',
            globalConfidence: 0.8,
            extractedAt: new \DateTimeImmutable(),
            classification: $classification,
        ));

        $handler = new ExtractDataMessageHandler(
            $documents,
            $extractor,
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        $handler(new ExtractDataMessage((int) $document->getId()));
    }

    public function testAConfidentDetectionReplacesTheUndeclaredType(): void
    {
        $document = $this->makeDocument(70);

        $this->runWithClassification(
            $document,
            new DocumentClassification(DocumentType::FACTURA, 0.91),
        );

        self::assertSame(DocumentType::FACTURA, $document->getDocumentType());
    }

    public function testADetectionAtOrBelowTheThresholdIsNotAdopted(): void
    {
        $document = $this->makeDocument(71);

        $this->runWithClassification(
            $document,
            new DocumentClassification(DocumentType::FACTURA, 0.7),
        );

        self::assertSame(DocumentType::ALT_DOCUMENT, $document->getDocumentType());
    }

    public function testATypeTheLawyerChoseIsNeverOverwritten(): void
    {
        $document = $this->makeDocument(72);
        $document->setDocumentType(DocumentType::CONTRACT);

        $this->runWithClassification(
            $document,
            new DocumentClassification(DocumentType::FACTURA, 0.99),
        );

        // A human decision outranks a confident model: relabelling evidence
        // behind the lawyer's back is worse than reading it with the generic
        // instructions.
        self::assertSame(DocumentType::CONTRACT, $document->getDocumentType());
    }

    public function testAResultWithoutAClassificationLeavesTheTypeAlone(): void
    {
        $document = $this->makeDocument(73);

        $this->runWithClassification($document, null);

        self::assertSame(DocumentType::ALT_DOCUMENT, $document->getDocumentType());
    }
}
