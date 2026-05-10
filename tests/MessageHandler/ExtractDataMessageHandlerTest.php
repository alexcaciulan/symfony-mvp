<?php

namespace App\Tests\MessageHandler;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\ExtractionStatus;
use App\Event\DataExtractedEvent;
use App\Message\ExtractDataMessage;
use App\MessageHandler\ExtractDataMessageHandler;
use App\Repository\DocumentRepository;
use App\Service\Extraction\DataExtractionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

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

    public function testCascadeThrowableSetsFailedFlushesAndDoesNotDispatchEvent(): void
    {
        $document = $this->makeDocument(8);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->willReturn($document);

        $extractor = $this->createMock(DataExtractionService::class);
        $extractor->method('extract')->willThrowException(new \RuntimeException('cascade exploded'));

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::never())
            ->method('dispatch');

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

    private function makeDocument(int $id): Document
    {
        $document = new Document();
        // Force an id via reflection — Document::getId() returns ?int and the
        // handler keys its log messages on it.
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, $id);

        return $document;
    }
}
