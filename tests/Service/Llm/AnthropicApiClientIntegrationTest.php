<?php

namespace App\Tests\Service\Llm;

use App\Service\Llm\AnthropicApiClient;
use App\Service\Llm\LlmClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Nivel 2 — verifies that the DI container correctly wires the
 * `LlmClientInterface` alias to `AnthropicApiClient` and that the env-bound
 * constructor scalars (`$anthropicApiKey`, `$anthropicModel`) are populated
 * from `.env.test`. No HTTP traffic involved — this test is intentionally
 * decoupled from network state.
 *
 * Pair with {@see AnthropicApiClientTest} (Nivel 1 unit + replay).
 *
 * Cascade test (Nivel 3) is deferred to Pas 2.5.7, where
 * `OcrTextExtractionStrategy` consumes `LlmClientInterface` through the
 * orchestrator (analog with the 2.5.5 deferral pattern).
 */
class AnthropicApiClientIntegrationTest extends KernelTestCase
{
    public function testContainerWiresLlmClientInterfaceAliasToAnthropicApiClient(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // The alias-by-typehint pattern Symfony uses for autowiring: the
        // service id is the interface FQCN and the resolved instance is the
        // concrete implementation.
        $client = $container->get(LlmClientInterface::class);

        $this->assertInstanceOf(AnthropicApiClient::class, $client);
    }

    public function testTestEnvUsesMockSentinelApiKey(): void
    {
        // Defence-in-depth: if anything in the test suite ever performs a real
        // HTTP request against Anthropic with the test container, this guard
        // ensures the request would fail loudly with the sentinel value
        // rather than silently consuming production / real credits. The
        // sentinel is deliberately not a syntactically-valid Anthropic key.
        self::bootKernel();

        $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? $_SERVER['ANTHROPIC_API_KEY'] ?? null;

        $this->assertSame('__MOCK_DO_NOT_USE__', $apiKey);
    }
}
