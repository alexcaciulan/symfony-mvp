<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Document;
use App\Enum\ExtractionStatus;
use App\Event\DataExtractedEvent;
use App\EventSubscriber\ExtractionMercurePublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class ExtractionMercurePublisherTest extends TestCase
{
    public function testPublishesMetadataOnlyPayloadOnCompletedDocument(): void
    {
        $document = $this->buildDocumentWithId(42, uploaderId: 7);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $document->setExtractionConfidence('0.93');

        $hub = new SpyHub();
        $subscriber = new ExtractionMercurePublisher($hub, new NullLogger());

        $subscriber->onDataExtracted(new DataExtractedEvent($document));

        self::assertCount(1, $hub->updates);
        $update = $hub->updates[0];
        // Topic is user-scoped + private:true so the Mercure hub gates delivery
        // on the subscriber JWT — anonymous EventSource connects can't enumerate
        // documents from other users (Pas 3.0 legal review W1 fix).
        self::assertSame(['user/7/document/42/extraction-status'], $update->getTopics());
        self::assertTrue($update->isPrivate(), 'Update must be private for JWT-gated subscriber access');

        $payload = json_decode($update->getData(), true);
        self::assertSame(42, $payload['documentId']);
        self::assertSame('COMPLETED', $payload['status']);
        self::assertSame(0.93, $payload['confidence']);
        // Anti-regression: payload must NOT contain extractedData (GDPR W1
        // from Pas 2.6 review — CNP/IBAN/addresses stay in the auth-gated
        // status endpoint, never on the Mercure stream).
        self::assertArrayNotHasKey('extractedData', $payload);
        self::assertArrayNotHasKey('creditor', $payload);
        self::assertArrayNotHasKey('debtor', $payload);
        self::assertArrayNotHasKey('claim', $payload);
    }

    public function testPublishesFailedStatusWithZeroConfidenceWhenColumnNull(): void
    {
        $document = $this->buildDocumentWithId(7, uploaderId: 11);
        $document->setExtractionStatus(ExtractionStatus::FAILED);
        // extractionConfidence remains null (cascade ran all strategies, all failed)

        $hub = new SpyHub();
        $subscriber = new ExtractionMercurePublisher($hub, new NullLogger());

        $subscriber->onDataExtracted(new DataExtractedEvent($document));

        $payload = json_decode($hub->updates[0]->getData(), true);
        self::assertSame('FAILED', $payload['status']);
        // json_encode emits 0.0 as "0" so the round-trip widens to int.
        // The browser-side consumer reads `data.confidence` as a Number so
        // both 0 and 0.0 work; we just check the numeric value is zero.
        self::assertSame(0, $payload['confidence'] + 0);
    }

    public function testHubFailureIsSwallowedNotRethrown(): void
    {
        $document = $this->buildDocumentWithId(99, uploaderId: 3);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);

        $hub = new SpyHub(throw: new \RuntimeException('hub unreachable'));
        $subscriber = new ExtractionMercurePublisher($hub, new NullLogger());

        // Must not throw — Mercure being down is non-fatal; the polling
        // fallback (Pas 3.0 extracted-data-poll controller) covers UI updates.
        $subscriber->onDataExtracted(new DataExtractedEvent($document));

        self::assertCount(0, $hub->updates);
    }

    private function buildDocumentWithId(int $id, int $uploaderId = 1): Document
    {
        $document = new Document();
        // Document::$id is private + auto-generated. The subscriber reads it via
        // getId(); we set it through reflection only here in unit tests.
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, $id);

        // Publisher reads uploadedBy.id to build user-scoped topic. Construct
        // a User with a known id via reflection — User::$id is also auto-gen.
        $user = new \App\Entity\User();
        $userIdProp = new \ReflectionProperty(\App\Entity\User::class, 'id');
        $userIdProp->setValue($user, $uploaderId);
        $document->setUploadedBy($user);

        return $document;
    }
}

/**
 * Minimal HubInterface stub that records published Update objects so the test
 * can introspect topic + payload. Hand-rolled (not PHPUnit mock) so the
 * structural assertions are obvious and we don't trigger the
 * "mock without expectations" notice on every test method.
 */
final class SpyHub implements HubInterface
{
    /** @var list<Update> */
    public array $updates = [];

    public function __construct(private readonly ?\Throwable $throw = null) {}

    public function publish(Update $update): string
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $this->updates[] = $update;

        return 'urn:uuid:test-event-' . count($this->updates);
    }

    public function getPublicUrl(): string
    {
        return 'http://mercure-spy.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }
}
