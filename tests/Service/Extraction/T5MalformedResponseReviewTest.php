<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Llm\LlmResponse;
use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
use App\Enum\LlmFinishReason;
use App\Service\AuditLogService;
use App\Service\Extraction\AiVisionExtractionStrategy;
use App\Service\Llm\LlmClientInterface;
use App\Tests\Support\ExtractionPrompts;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Adversarial review of the RESPONSE_MALFORMED defect (scenario 8).
 *
 * Observed live: three invoices of identical shape, uploaded without a declared
 * type, and two of the three came back "malformed_ai_response" while the third
 * did not; declaring the type by hand made all three work. The cause is the
 * generic prompt, the only one asking for two tasks in one answer, and a
 * salvage step that read from the first brace to the last, spanning the gap
 * between two separate objects and swallowing trailing prose.
 *
 * These tests drive the reader with the answer shapes that produced the failure
 * and demand an extraction, not a dead end.
 */
final class T5MalformedResponseReviewTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->uploadsDir = sys_get_temp_dir() . '/t5-malformed-' . uniqid('', true);
        mkdir($this->uploadsDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadsDir)) {
            foreach (glob($this->uploadsDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->uploadsDir);
        }
    }

    /**
     * The reproduction: three identically shaped invoices with no declared type,
     * each answered in the two-object shape the generic prompt provokes. All
     * three must extract. Under the old salvage, every one of them decoded to
     * nothing.
     */
    public function testThreeAutoDetectedInvoicesAllExtractFromTheTwoObjectAnswer(): void
    {
        foreach ([101, 102, 103] as $index => $documentId) {
            $answer = $this->twoObjectAnswer(1200.0 + $index, 'FF-' . $documentId);
            $result = $this->makeStrategy($this->fakeLlmClient($answer))
                ->extract($this->makeDocument($documentId));

            self::assertNull(
                $result->failureReason,
                sprintf('document %d must extract, not report a malformed answer', $documentId),
            );
            self::assertSame(1200.0 + $index, $result->claim?->amount);
            self::assertSame(DocumentType::FACTURA, $result->classification?->type);
        }
    }

    public function testTheSameDocumentWithADeclaredTypeAlsoExtracts(): void
    {
        // The live workaround the lawyer was forced into. It must keep working,
        // and it must produce the same claim the auto-detected path now does.
        $answer = $this->singleObjectAnswer(1200.0, 'FF-101');

        $result = $this->makeStrategy($this->fakeLlmClient($answer))
            ->extract($this->makeDocument(101, DocumentType::FACTURA));

        self::assertNull($result->failureReason);
        self::assertSame(1200.0, $result->claim?->amount);
    }

    public function testTwoFencedObjectsOnePerTaskAreBothRead(): void
    {
        // The exact shape: classification in its own fence, extraction in
        // another, with prose between them. The old salvage spanned the gap and
        // decoded nothing at all.
        $answer = $this->twoObjectAnswer(950.0, 'FF-7');

        $result = $this->makeStrategy($this->fakeLlmClient($answer))->extract($this->makeDocument(7));

        self::assertNull($result->failureReason);
        self::assertSame(DocumentType::FACTURA, $result->classification?->type, 'the first object is read');
        self::assertSame(950.0, $result->claim?->amount, 'and so is the second');
    }

    public function testTrailingProseContainingBracesDoesNotDestroyTheAnswer(): void
    {
        // A closing remark with braces after a perfectly good object. Reading to
        // the last brace swallowed the remark and decoded nothing.
        $answer = $this->singleObjectAnswer(430.0, 'FF-8')
            . "\n\nObservație: câmpurile lipsă {nu apar} în document.";

        $result = $this->makeStrategy($this->fakeLlmClient($answer))->extract($this->makeDocument(8));

        self::assertNull($result->failureReason);
        self::assertSame(430.0, $result->claim?->amount);
    }

    public function testABraceInsideAStringValueDoesNotEndTheObjectEarly(): void
    {
        // The scanner must ignore braces inside strings, or a description
        // quoting one truncates the object and loses the whole reading.
        $answer = json_encode([
            'classification' => null,
            'creditor' => null,
            'debtor' => null,
            'claim' => [
                'amount' => 777.0,
                'currency' => 'RON',
                'description' => 'Servicii conform anexa {A} si {B}',
                'confidencePerField' => ['amount' => 0.95, 'currency' => 0.95, 'description' => 0.9],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->makeStrategy($this->fakeLlmClient("Rezultat:\n" . $answer . "\nGata."))
            ->extract($this->makeDocument(9));

        self::assertNull($result->failureReason);
        self::assertSame(777.0, $result->claim?->amount);
    }

    /**
     * The answer that actually caused the reported failure, captured verbatim
     * from the provider. The model cites the document title the way Romanian
     * writes it, opening with „ and closing with a straight quote, which ends
     * the JSON string in the middle of the sentence and takes every party and
     * every figure in the answer down with it. Only the generic prompt asks for
     * a rationale, which is why the failure followed auto-detected type and
     * never appeared once the lawyer declared it.
     */
    public function testACitationClosedWithAStraightQuoteStillExtracts(): void
    {
        $answer = '{"classification":{"type":"factura","confidence":0.97,'
            . '"subtype":"factură fiscală",'
            . '"rationale":"Document intitulat „FACTURĂ FISCALĂ" cu serie și număr."},'
            . '"creditor":null,"debtor":null,'
            . '"claim":{"amount":10000.00,"currency":"RON","invoiceNumber":"MJ 9",'
            . '"confidencePerField":{"amount":0.97,"currency":0.95,"invoiceNumber":0.9}}}';

        $result = $this->makeStrategy($this->fakeLlmClient($answer))->extract($this->makeDocument(11));

        self::assertNull($result->failureReason);
        self::assertSame(10000.0, $result->claim?->amount);
        self::assertSame('MJ 9', $result->claim?->invoiceNumber);
        self::assertSame(DocumentType::FACTURA, $result->classification?->type);
    }

    /**
     * The repair must not rescue a quote that ends a value, or a description
     * would swallow the keys after it and the reading would be silently wrong
     * rather than merely absent.
     */
    public function testAQuoteThatLegitimatelyEndsAValueIsLeftAlone(): void
    {
        $answer = json_encode([
            'classification' => null,
            'creditor' => null,
            'debtor' => null,
            'claim' => [
                'amount' => 1234.0,
                'currency' => 'RON',
                'description' => 'Servicii de consultanță',
                'invoiceNumber' => 'FF-1',
                'confidencePerField' => ['amount' => 0.95, 'currency' => 0.95, 'invoiceNumber' => 0.9],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->makeStrategy($this->fakeLlmClient($answer))->extract($this->makeDocument(12));

        self::assertNull($result->failureReason);
        self::assertSame(1234.0, $result->claim?->amount);
        self::assertSame('FF-1', $result->claim?->invoiceNumber);
        self::assertSame('Servicii de consultanță', $result->claim?->description);
    }

    public function testAnAnswerThatIsGenuinelyNotJsonIsStillReportedAsMalformed(): void
    {
        // The salvage must not become so permissive that a refusal reads as an
        // empty but valid extraction; the lawyer would then be told the file
        // carries no data rather than that it needs reprocessing.
        $result = $this->makeStrategy($this->fakeLlmClient('Nu pot analiza acest document.'))
            ->extract($this->makeDocument(10));

        self::assertSame(ExtractionFailureReason::RESPONSE_MALFORMED, $result->failureReason);
    }

    public function testAMalformedAnswerIsTransientSoTheLawyerIsOfferedARetry(): void
    {
        // An answer the reader could not decode is a sampling accident, not a
        // property of the document. Without this the lawyer has no way forward
        // except correcting the type by hand, which is precisely what was
        // observed live.
        self::assertTrue(ExtractionFailureReason::RESPONSE_MALFORMED->isTransient());
    }

    // ---------- fixtures ----------

    /**
     * One answer, two top-level objects, each fenced, with prose around them:
     * what a model does when a single call asks it to classify and to extract.
     */
    private function twoObjectAnswer(float $amount, string $invoiceNumber): string
    {
        $classification = json_encode([
            'classification' => ['type' => 'factura', 'confidence' => 0.95, 'subtype' => null, 'rationale' => null],
        ], JSON_THROW_ON_ERROR);

        $extraction = json_encode([
            'creditor' => null,
            'debtor' => null,
            'claim' => [
                'amount' => $amount,
                'currency' => 'RON',
                'invoiceNumber' => $invoiceNumber,
                'confidencePerField' => ['amount' => 0.96, 'currency' => 0.96, 'invoiceNumber' => 0.94],
            ],
        ], JSON_THROW_ON_ERROR);

        return "Sarcina 1, clasificare:\n```json\n" . $classification
            . "\n```\n\nSarcina 2, extragere:\n```json\n" . $extraction . "\n```";
    }

    private function singleObjectAnswer(float $amount, string $invoiceNumber): string
    {
        return json_encode([
            'classification' => ['type' => 'factura', 'confidence' => 0.95, 'subtype' => null, 'rationale' => null],
            'creditor' => null,
            'debtor' => null,
            'claim' => [
                'amount' => $amount,
                'currency' => 'RON',
                'invoiceNumber' => $invoiceNumber,
                'confidencePerField' => ['amount' => 0.96, 'currency' => 0.96, 'invoiceNumber' => 0.94],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    // ---------- harness ----------

    private function makeStrategy(LlmClientInterface $llmClient): AiVisionExtractionStrategy
    {
        return new AiVisionExtractionStrategy(
            llmClient: $llmClient,
            promptRegistry: ExtractionPrompts::registry(),
            auditLogService: $this->fakeAuditLogService(),
            extractionAiVisionLimiter: $this->noLimitFactory(),
            extractionAiVisionBurstLimiter: $this->noLimitFactory(),
            uploadsDir: $this->uploadsDir,
            anthropicApiKey: 'sk-ant-t5-review',
            logger: new NullLogger(),
        );
    }

    private function fakeLlmClient(string $content): LlmClientInterface
    {
        $client = new class implements LlmClientInterface {
            public string $cannedContent = '{}';

            public function complete(
                array $messages,
                int $maxTokens = 2048,
                ?array $documentParts = null,
                bool $cacheSystemPrompt = false,
                ?array $outputSchema = null,
            ): LlmResponse {
                return new LlmResponse(
                    content: $this->cannedContent,
                    tokensIn: 1500,
                    tokensOut: 300,
                    finishReason: LlmFinishReason::COMPLETED,
                );
            }
        };
        $client->cannedContent = $content;

        return $client;
    }

    private function fakeAuditLogService(): AuditLogService
    {
        return new class extends AuditLogService {
            public function __construct() {}

            public function log(
                string $action,
                string $entityType,
                string $entityId,
                ?array $oldData = null,
                ?array $newData = null,
                ?string $category = null,
            ): AuditLog {
                return new AuditLog();
            }
        };
    }

    private function noLimitFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 't5_malformed_no_limit', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }

    private function makeDocument(int $id, DocumentType $documentType = DocumentType::ALT_DOCUMENT): Document
    {
        $storedFilename = 'doc-' . $id . '.png';
        file_put_contents($this->uploadsDir . '/' . $storedFilename, "\x89PNG\r\n\x1a\n");

        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);

        $case = new LegalCase();
        $case->setUser($user);

        $document = new Document();
        $document->setDocumentType($documentType);
        $document->setLegalCase($case);
        $document->setOriginalFilename($storedFilename);
        $document->setStoredFilename($storedFilename);
        $document->setFileSize(1024);
        $document->setMimeType('image/png');
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }
}
