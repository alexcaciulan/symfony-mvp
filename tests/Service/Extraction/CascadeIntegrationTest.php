<?php

namespace App\Tests\Service\Extraction;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionStatus;
use App\Service\Extraction\DataExtractionService;
use App\Service\Extraction\PdfParserExtractionStrategy;
use App\Service\Extraction\StubExtractionStrategy;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end cascade integration tests with REAL strategy implementations
 * (no fakes / mocks) and the static realistic PDF fixtures from
 * tests/fixtures/extraction/.
 *
 * The other test classes in this folder cover narrower concerns:
 *   - DataExtractionServiceTest   — orchestrator with anonymous fake strategies
 *                                   (no I/O, no PDF) — tests cascade logic.
 *   - PdfParserExtractionStrategyTest — PdfParser unit tests with trivial
 *                                       runtime-rendered PDFs.
 *   - PdfParserExtractionStrategyIntegrationTest — PdfParser alone against
 *                                                  realistic static fixtures.
 *
 * This class wires it all together: orchestrator + PdfParser + Stub +
 * realistic PDF fixtures. It validates the contract that:
 *   - A text-based PDF cascades to PdfParser (priority 100), short-circuits.
 *   - A corrupt or text-poor PDF falls through to Stub (priority 10) and is
 *     persisted as FAILED (globalConfidence == 0.0 → status FAILED per Pas 2.5.2).
 *   - LOCAL_ONLY mode does not affect this layer (PdfParser + Stub are both
 *     non-AI), but is still resolved correctly via Document → LegalCase → User.
 */
class CascadeIntegrationTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../fixtures/extraction';

    private DataExtractionService $orchestrator;

    protected function setUp(): void
    {
        $strategies = [
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            new StubExtractionStrategy(),
        ];
        $this->orchestrator = new DataExtractionService($strategies);
    }

    public function testRealisticInvoiceCascadesToPdfParserAndShortCircuits(): void
    {
        $document = $this->makeDocument('invoice-realistic.pdf');

        $result = $this->orchestrator->extract($document);

        // PdfParser (priority 100) wins — Stub never runs.
        $this->assertSame(PdfParserExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertGreaterThan(0.6, $result->globalConfidence);

        // Document was mutated and persisted as COMPLETED (confidence > 0).
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        $this->assertSame('pdf_parser', $document->getExtractionStrategy());
        $this->assertNotNull($document->getExtractedData());

        // Real fields ended up persisted on the JSON column.
        $this->assertSame('15193236', $document->getExtractedData()['creditor']['cui']);
        $this->assertSame('14186770', $document->getExtractedData()['debtor']['cui']);
    }

    public function testCorruptPdfCascadesAllTheWayToStub(): void
    {
        // First write a corrupt PDF in the fixtures dir (the static fixtures are
        // all valid; we need an explicit broken case to exercise Stub fallback).
        $corruptPath = self::FIXTURES_DIR . '/_cascade-test-corrupt.pdf';
        file_put_contents($corruptPath, 'NOT A PDF AT ALL');

        try {
            $document = $this->makeDocument('_cascade-test-corrupt.pdf');

            $result = $this->orchestrator->extract($document);

            // PdfParser.supports() returned false (smalot threw) → cascade fell to Stub.
            $this->assertSame(StubExtractionStrategy::STRATEGY_KEY, $result->strategy);
            $this->assertSame(0.0, $result->globalConfidence);

            // Stub fallback with confidence 0 must persist as FAILED, not COMPLETED.
            $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
            $this->assertSame('stub', $document->getExtractionStrategy());
        } finally {
            @unlink($corruptPath);
        }
    }

    public function testCascadeExtractsCnpFromLoanContract(): void
    {
        $document = $this->makeDocument('loan-individual.pdf');

        $result = $this->orchestrator->extract($document);

        $this->assertSame('pdf_parser', $result->strategy);
        $this->assertNotNull($result->debtor);
        $this->assertSame('1980715221232', $result->debtor->personalId);

        // Persisted JSON contains the CNP — Pas 2.5.4 will introduce PiiMasker
        // for AI prompts, but at this layer raw values are kept (the data never
        // leaves the server in this strategy chain).
        $persisted = $document->getExtractedData();
        $this->assertSame('1980715221232', $persisted['debtor']['personalId']);
    }

    public function testCascadeRespectsUserLocalOnlyMode(): void
    {
        // Both PdfParser and Stub are non-AI, so LOCAL_ONLY behaves the same as
        // BALANCED for this layer. We still want to assert the orchestrator
        // resolves the mode without errors when the user opts out of AI.
        $document = $this->makeDocument('invoice-realistic.pdf', userMode: ExtractionMode::LOCAL_ONLY);

        $result = $this->orchestrator->extract($document);

        $this->assertSame('pdf_parser', $result->strategy);
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
    }

    public function testCascadeAcrossAllRealisticFixturesProducesUsefulData(): void
    {
        foreach (['invoice-realistic.pdf', 'contract-multipage.pdf', 'loan-individual.pdf'] as $filename) {
            $document = $this->makeDocument($filename);

            $result = $this->orchestrator->extract($document);

            $this->assertSame(
                'pdf_parser',
                $result->strategy,
                "Realistic fixture {$filename} must cascade to PdfParser, not Stub",
            );
            $this->assertSame(
                ExtractionStatus::COMPLETED,
                $document->getExtractionStatus(),
                "Realistic fixture {$filename} must persist as COMPLETED",
            );
            $this->assertNotNull($result->creditor, "Creditor should be extracted from {$filename}");
            $this->assertSame('15193236', $result->creditor->cui, "Creditor CUI mismatch on {$filename}");
        }
    }

    private function makeDocument(
        string $filename,
        string $mime = 'application/pdf',
        ExtractionMode $userMode = ExtractionMode::LOCAL_ONLY,
    ): Document {
        $user = new User();
        $user->setExtractionMode($userMode);

        $case = new LegalCase();
        $case->setUser($user);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setStoredFilename($filename);
        $document->setMimeType($mime);

        return $document;
    }
}
