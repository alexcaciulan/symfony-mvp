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
use App\Service\Extraction\CoverageConfidenceCalculator;
use App\Service\Llm\LlmClientInterface;
use App\Tests\Support\ExtractionPrompts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The responses a constrained decoder can legitimately produce and a hand
 * written happy-path fixture never does: every key present and null, a date
 * that satisfies `type: string` and no calendar, a classification the model
 * itself is unsure of, a confidence score attached to a field left empty.
 *
 * Constrained decoding guarantees the shape of the answer. Everything these
 * tests are about is what the shape does not say.
 */
final class AiVisionTypedExtractionAdversarialTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->uploadsDir = sys_get_temp_dir() . '/aivision-adversarial-' . uniqid('', true);
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

    // ---------- an answer that says "nothing here" ----------

    public function testADocumentWithNothingToExtractIsNotReportedAsAParseFailure(): void
    {
        // What the schema forces a model to answer for a page it read fine and
        // found nothing usable on: every key present, every value null. The
        // lawyer's fix ("this file carries no data, upload another") has
        // nothing to do with the one RESPONSE_MALFORMED points at ("the model
        // answered garbage, reprocess"), and a permanent reason parks the
        // document on FAILED either way.
        $response = json_encode([
            'classification' => null,
            'creditor' => null,
            'debtor' => null,
            'claim' => null,
        ], JSON_THROW_ON_ERROR);

        $result = $this->makeStrategy($this->fakeLlmClient($response))->extract($this->makeDocument());

        self::assertNotSame(
            ExtractionFailureReason::RESPONSE_MALFORMED,
            $result->failureReason,
            'An empty but well-formed answer is not a malformed one',
        );
    }

    public function testAClassifiedButOtherwiseEmptyAnswerIsAccepted(): void
    {
        // Same answer with a classification filled in. This one is accepted,
        // which is what makes the case above a reader defect rather than a
        // deliberate policy: the two answers differ only in a key the parser
        // happens to look at with `isset`.
        $response = json_encode([
            'classification' => ['type' => 'proces_verbal', 'confidence' => 0.9, 'subtype' => null, 'rationale' => null],
            'creditor' => null,
            'debtor' => null,
            'claim' => null,
        ], JSON_THROW_ON_ERROR);

        $result = $this->makeStrategy($this->fakeLlmClient($response))->extract($this->makeDocument());

        self::assertNull($result->failureReason);
    }

    // ---------- dates that fit the schema and not the calendar ----------

    /**
     * @param string $emitted date as the model wrote it
     */
    #[DataProvider('impossibleDates')]
    public function testADateThatDoesNotExistIsRejectedRatherThanRolledForward(string $emitted): void
    {
        // `dueDate` is declared as a plain string in the response schema, so a
        // constrained decoder will emit whatever it read, including a date that
        // no calendar has. Rolling it forward silently moves the moment the
        // debt fell due, which is the input to the interest calculation and to
        // the default date pleaded in the filing.
        $result = $this->makeStrategy($this->fakeLlmClient($this->claimResponse($emitted)))
            ->extract($this->makeDocument());

        self::assertNull(
            $result->claim?->dueDate,
            sprintf('%s is not a date and must not be turned into one', $emitted),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function impossibleDates(): iterable
    {
        yield 'february 31st' => ['2024-02-31'];
        yield 'month thirteen' => ['2024-13-05'];
        yield 'november 31st' => ['2025-11-31'];
        yield 'february 29th of a common year' => ['2023-02-29'];
    }

    public function testARealDateIsStillAccepted(): void
    {
        $result = $this->makeStrategy($this->fakeLlmClient($this->claimResponse('2026-02-01')))
            ->extract($this->makeDocument());

        self::assertSame('2026-02-01', $result->claim?->dueDate?->format('Y-m-d'));
    }

    // ---------- classification feeding the coverage score ----------

    public function testAClassificationTheModelIsUnsureOfDoesNotNarrowTheCoverageDenominator(): void
    {
        // A guess at 0.10 is explicitly not trusted enough to become the
        // document's type (the handler adopts nothing below 0.7), yet the same
        // guess picks the field set the extraction is measured against. Three
        // fields then read as a complete extraction, the cascade stops, and the
        // badge tells the lawyer the document was fully read.
        $response = json_encode([
            'classification' => ['type' => 'extras_cont', 'confidence' => 0.10, 'subtype' => null, 'rationale' => null],
            'creditor' => [
                'name' => 'Tehno Construct SRL',
                'iban' => 'RO49AAAA1B31007593840000',
                'bankName' => 'Banca Transilvania',
                'confidencePerField' => ['name' => 1.0, 'iban' => 1.0, 'bankName' => 1.0],
            ],
            'debtor' => null,
            'claim' => null,
        ], JSON_THROW_ON_ERROR);

        $result = $this->makeStrategy($this->fakeLlmClient($response))->extract($this->makeDocument());

        self::assertSame(
            CoverageConfidenceCalculator::compute(
                ['name' => 1.0, 'iban' => 1.0, 'bankName' => 1.0],
                null,
                null,
            ),
            $result->globalConfidence,
            'An untrusted type guess must not shrink the field set the score is computed over',
        );
    }

    public function testAConfidentClassificationMayNarrowTheDenominator(): void
    {
        // The counterpart, and the behaviour the tranche is after: a detection
        // the handler would adopt is one the score may rely on.
        $response = json_encode([
            'classification' => ['type' => 'extras_cont', 'confidence' => 0.95, 'subtype' => null, 'rationale' => null],
            'creditor' => [
                'name' => 'Tehno Construct SRL',
                'iban' => 'RO49AAAA1B31007593840000',
                'bankName' => 'Banca Transilvania',
                'confidencePerField' => ['name' => 1.0, 'iban' => 1.0, 'bankName' => 1.0],
            ],
            'debtor' => null,
            'claim' => null,
        ], JSON_THROW_ON_ERROR);

        $result = $this->makeStrategy($this->fakeLlmClient($response))->extract($this->makeDocument());

        self::assertSame(
            CoverageConfidenceCalculator::computeForType(
                DocumentType::EXTRAS_CONT,
                ['name' => 1.0, 'iban' => 1.0, 'bankName' => 1.0],
                null,
                null,
            ),
            $result->globalConfidence,
        );
    }

    public function testAScoreForAFieldTheModelLeftEmptyDoesNotCountAsCoverage(): void
    {
        // The closed schema requires every confidence key to be present, so
        // nothing stops a model from returning `name: null` next to
        // `confidencePerField.name: 1.0`. Counting that pair as coverage
        // reports a perfectly read document with no data in it: the cascade
        // stops, the status is COMPLETED and the wizard prefills nothing.
        $response = json_encode([
            'classification' => null,
            'creditor' => [
                'personType' => null,
                'name' => null,
                'cui' => null,
                'personalId' => null,
                'onrcNumber' => null,
                'address' => null,
                'email' => null,
                'phone' => null,
                'iban' => null,
                'legalRepresentative' => null,
                'confidencePerField' => [
                    'personType' => 1.0,
                    'name' => 1.0,
                    'cui' => 1.0,
                    'personalId' => 1.0,
                    'onrcNumber' => 1.0,
                    'address' => 1.0,
                    'email' => 1.0,
                    'phone' => 1.0,
                    'iban' => 1.0,
                    'legalRepresentative' => 1.0,
                ],
            ],
            'debtor' => null,
            'claim' => null,
        ], JSON_THROW_ON_ERROR);

        $result = $this->makeStrategy($this->fakeLlmClient($response))->extract($this->makeDocument());

        self::assertSame(
            0.0,
            $result->globalConfidence,
            'Coverage counts fields that were extracted, not fields that were scored',
        );
    }

    // ---------- budgets and truncation ----------

    public function testTruncationIsReportedEvenWhenTheCutOutputWouldStillParse(): void
    {
        // A ledger-style answer cut mid-list can end on a syntactically valid
        // object once the salvage step trims it, and would then be persisted as
        // a complete reading with half the invoices missing.
        $truncated = '{"creditor": {"name": "Tehno Construct SRL", "confidencePerField": {"name": 1.0}}}';

        $result = $this->makeStrategy($this->fakeLlmClient($truncated, finishReason: LlmFinishReason::MAX_TOKENS))
            ->extract($this->makeDocument(documentType: DocumentType::FACTURA));

        self::assertSame(ExtractionFailureReason::RESPONSE_TRUNCATED, $result->failureReason);
        self::assertSame(0.0, $result->globalConfidence);
        self::assertNull($result->creditor);
    }

    public function testATruncatedAnswerIsNotHandedBackToTheQueue(): void
    {
        // Truncation repeats identically on every retry: the budget is a
        // property of the prompt, not of the moment. Retrying it would spend
        // the daily AI allowance re-reading the same file.
        self::assertFalse(ExtractionFailureReason::RESPONSE_TRUNCATED->isTransient());
    }

    public function testEachTypeAsksForItsOwnBudgetAndSchema(): void
    {
        $client = $this->fakeLlmClient($this->claimResponse('2026-02-01'));

        $this->makeStrategy($client)->extract($this->makeDocument(documentType: DocumentType::FACTURA));
        self::assertSame(16000, $client->lastMaxTokens);
        // The lawyer already said what this is; asking again invites the model
        // to contradict them.
        self::assertArrayNotHasKey('classification', $client->lastOutputSchema['properties']);

        $this->makeStrategy($client)->extract($this->makeDocument(documentType: DocumentType::CONTRACT));
        self::assertSame(4096, $client->lastMaxTokens);

        $this->makeStrategy($client)->extract($this->makeDocument());
        // The classifying pass sees invoices too, so it gets the ledger budget.
        self::assertSame(16000, $client->lastMaxTokens);
        self::assertArrayHasKey('classification', $client->lastOutputSchema['properties']);
    }

    public function testADeclaredTypeScoresCoverageEvenWithoutAClassification(): void
    {
        // A specialised prompt returns no classification, so the declared type
        // is the only thing that can narrow the field set. If it were ignored,
        // every reprocess after a type correction would score worse than the
        // first pass that guessed the type itself.
        $response = json_encode([
            'creditor' => [
                'name' => 'Tehno Construct SRL',
                'iban' => 'RO49AAAA1B31007593840000',
                'bankName' => 'Banca Transilvania',
                'confidencePerField' => ['name' => 1.0, 'iban' => 1.0, 'bankName' => 1.0],
            ],
            'debtor' => null,
            'claim' => null,
        ], JSON_THROW_ON_ERROR);

        $result = $this->makeStrategy($this->fakeLlmClient($response))
            ->extract($this->makeDocument(documentType: DocumentType::EXTRAS_CONT));

        self::assertNull($result->classification);
        self::assertSame(
            CoverageConfidenceCalculator::computeForType(
                DocumentType::EXTRAS_CONT,
                ['name' => 1.0, 'iban' => 1.0, 'bankName' => 1.0],
                null,
                null,
            ),
            $result->globalConfidence,
        );
    }

    // ---------- harness ----------

    /**
     * A response carrying one claim with the given due date, in the shape the
     * schema produces.
     */
    private function claimResponse(string $dueDate): string
    {
        return json_encode([
            'classification' => null,
            'creditor' => null,
            'debtor' => null,
            'claim' => [
                'amount' => 12000.0,
                'currency' => 'RON',
                'dueDate' => $dueDate,
                'confidencePerField' => ['amount' => 0.99, 'currency' => 0.99, 'dueDate' => 0.95],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function makeStrategy(?LlmClientInterface $llmClient = null): AiVisionExtractionStrategy
    {
        return new AiVisionExtractionStrategy(
            llmClient: $llmClient ?? $this->fakeLlmClient('{}'),
            promptRegistry: ExtractionPrompts::registry(),
            auditLogService: $this->fakeAuditLogService(),
            extractionAiVisionLimiter: $this->noLimitFactory(),
            extractionAiVisionBurstLimiter: $this->noLimitFactory(),
            uploadsDir: $this->uploadsDir,
            anthropicApiKey: 'sk-ant-adversarial-test',
            logger: new NullLogger(),
        );
    }

    private function fakeLlmClient(
        string $content,
        LlmFinishReason $finishReason = LlmFinishReason::COMPLETED,
    ): LlmClientInterface {
        $client = new class implements LlmClientInterface {
            public ?int $lastMaxTokens = null;
            /** @var array<string, mixed>|null */
            public ?array $lastOutputSchema = null;
            public string $cannedContent = '{}';
            public LlmFinishReason $cannedFinishReason = LlmFinishReason::COMPLETED;

            public function complete(
                array $messages,
                int $maxTokens = 2048,
                ?array $documentParts = null,
                bool $cacheSystemPrompt = false,
                ?array $outputSchema = null,
            ): LlmResponse {
                $this->lastMaxTokens = $maxTokens;
                $this->lastOutputSchema = $outputSchema;

                return new LlmResponse(
                    content: $this->cannedContent,
                    tokensIn: 1500,
                    tokensOut: 200,
                    finishReason: $this->cannedFinishReason,
                );
            }
        };
        $client->cannedContent = $content;
        $client->cannedFinishReason = $finishReason;

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
            ['id' => 'aivision_adversarial_no_limit', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }

    private function makeDocument(DocumentType $documentType = DocumentType::ALT_DOCUMENT): Document
    {
        $storedFilename = 'doc.png';
        file_put_contents($this->uploadsDir . '/' . $storedFilename, "\x89PNG\r\n\x1a\n");

        $user = new User();
        (new \ReflectionClass($user))->getProperty('id')->setValue($user, 42);

        $case = new LegalCase();
        $case->setUser($user);

        $document = new Document();
        $document->setDocumentType($documentType);
        $document->setLegalCase($case);
        $document->setOriginalFilename($storedFilename);
        $document->setStoredFilename($storedFilename);
        $document->setFileSize(1024);
        $document->setMimeType('image/png');
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, 99);

        return $document;
    }
}
