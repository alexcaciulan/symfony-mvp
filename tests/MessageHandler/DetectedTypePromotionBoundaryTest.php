<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\DTO\Extraction\DocumentClassification;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Message\ExtractDataMessage;
use App\MessageHandler\ExtractDataMessageHandler;
use App\Repository\DocumentRepository;
use App\Service\Extraction\DataExtractionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

/**
 * The exact score at which a detected type stops being a suggestion and starts
 * deciding which instructions read the document next time. The value is a
 * product decision, so it is pinned on both sides of the line rather than
 * sampled somewhere comfortably far from it.
 */
final class DetectedTypePromotionBoundaryTest extends TestCase
{
    #[DataProvider('scoresAroundTheLine')]
    public function testAdoptionFollowsTheScoreOnBothSidesOfTheLine(float $confidence, bool $expectedAdoption): void
    {
        $document = $this->makeDocument(DocumentType::ALT_DOCUMENT);

        $this->handle($document, new DocumentClassification(DocumentType::FACTURA, $confidence));

        self::assertSame(
            $expectedAdoption ? DocumentType::FACTURA : DocumentType::ALT_DOCUMENT,
            $document->getDocumentType(),
        );
    }

    /**
     * @return iterable<string, array{float, bool}>
     */
    public static function scoresAroundTheLine(): iterable
    {
        yield 'just above' => [0.71, true];
        yield 'exactly on the line' => [0.70, false];
        yield 'just below' => [0.69, false];
        yield 'no signal at all' => [0.0, false];
        yield 'certain' => [1.0, true];
    }

    #[DataProvider('typesTheLawyerMayHaveChosen')]
    public function testACertainDetectionNeverOverridesATypeAHumanPicked(DocumentType $chosen): void
    {
        // Anything other than the wizard's placeholder is a decision somebody
        // made, including a correction made after a previous detection.
        $document = $this->makeDocument($chosen);

        $this->handle($document, new DocumentClassification(DocumentType::FACTURA, 0.95));

        self::assertSame($chosen, $document->getDocumentType());
    }

    /**
     * @return iterable<string, array{DocumentType}>
     */
    public static function typesTheLawyerMayHaveChosen(): iterable
    {
        yield 'contract' => [DocumentType::CONTRACT];
        yield 'bank statement' => [DocumentType::EXTRAS_CONT];
        // Same family as the detection: still a human decision, and adopting it
        // would look like a no-op while overwriting the source of the value.
        yield 'invoice' => [DocumentType::FACTURA];
    }

    public function testAGeneratedTypeIsNeverAdoptedHoweverCertainTheDetection(): void
    {
        // The platform produces these itself; a document carrying one is read
        // as a filing rather than as evidence. The vision strategy refuses to
        // build such a classification, but nothing in the type system does, so
        // the step that acts on it is the one that has to hold the line.
        $document = $this->makeDocument(DocumentType::ALT_DOCUMENT);

        $this->handle($document, new DocumentClassification(DocumentType::SOMATIE, 0.99));

        self::assertSame(DocumentType::ALT_DOCUMENT, $document->getDocumentType());
    }

    private function handle(Document $document, ?DocumentClassification $classification): void
    {
        $documents = $this->createStub(DocumentRepository::class);
        $documents->method('find')->willReturn($document);

        $extractor = $this->createStub(DataExtractionService::class);
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
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        $handler(new ExtractDataMessage((int) $document->getId()));
    }

    private function makeDocument(DocumentType $type): Document
    {
        $document = new Document();
        $document->setDocumentType($type);
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, 81);

        return $document;
    }
}
