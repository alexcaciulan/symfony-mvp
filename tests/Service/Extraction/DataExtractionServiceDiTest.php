<?php

namespace App\Tests\Service\Extraction;

use App\Service\Extraction\AiVisionExtractionStrategy;
use App\Service\Extraction\DataExtractionService;
use App\Service\Extraction\ExtractionStrategyInterface;
use App\Service\Extraction\OcrTextExtractionStrategy;
use App\Service\Extraction\PdfParserExtractionStrategy;
use App\Service\Extraction\StubExtractionStrategy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies that Symfony's DI container correctly wires `DataExtractionService`
 * with all four extraction strategies via the `app.extraction_strategy` tag
 * (declared in `config/services.yaml` `_instanceof` block) and that the
 * `tagged_iterator` argument resolution preserves expected priority ordering.
 *
 * Why this is its own test: `CascadeIntegrationTest` instantiates the
 * orchestrator manually with hand-picked strategies in setUp, which is how the
 * cascade contract is verified at the integration layer. But that bypasses
 * the DI wiring: a regression in `services.yaml` (e.g. accidental untagging
 * of one strategy, or `_instanceof` typo) wouldn't be caught by any of the
 * cascade tests because they don't touch the container. This test fetches
 * the orchestrator from the real container and asserts the strategy iterable
 * has the production shape.
 */
class DataExtractionServiceDiTest extends KernelTestCase
{
    public function testOrchestratorIsInjectedWithAllFourTaggedStrategies(): void
    {
        self::bootKernel();
        // Fetch via test-only public alias — see config/packages/test/services.yaml
        // for why the FQCN id can't be retrieved directly until a real consumer
        // (Pas 3.0 wizard controller) injects it.
        $orchestrator = static::getContainer()->get('test.public.data_extraction_service');
        $this->assertInstanceOf(DataExtractionService::class, $orchestrator);

        $reflection = new \ReflectionClass($orchestrator);
        $strategiesProp = $reflection->getProperty('sortedStrategies');
        $strategies = $strategiesProp->getValue($orchestrator);

        $this->assertIsArray($strategies);
        $this->assertCount(
            4,
            $strategies,
            'Container must inject exactly 4 tagged extraction strategies (PdfParser, OcrText, AiVision, Stub)',
        );

        // Cascade contract: priority DESC. Any reordering or missing strategy
        // breaks the production cascade short-circuit logic.
        $priorities = array_map(static fn (ExtractionStrategyInterface $s) => $s->priority(), $strategies);
        $this->assertSame([100, 70, 50, 10], $priorities);

        // Type-check each slot — defends against a refactor that swaps two
        // strategies but happens to preserve the priority numbers.
        $this->assertInstanceOf(PdfParserExtractionStrategy::class, $strategies[0]);
        $this->assertInstanceOf(OcrTextExtractionStrategy::class, $strategies[1]);
        $this->assertInstanceOf(AiVisionExtractionStrategy::class, $strategies[2]);
        $this->assertInstanceOf(StubExtractionStrategy::class, $strategies[3]);
    }
}
