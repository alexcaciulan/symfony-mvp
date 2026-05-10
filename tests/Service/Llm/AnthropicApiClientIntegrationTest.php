<?php

namespace App\Tests\Service\Llm;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Nivel 2 — verifies that the DI container correctly wires the LLM stack
 * end-to-end and that env-bound configuration matches the test sentinel.
 *
 * Pair with {@see AnthropicApiClientTest} (Nivel 1 unit + replay).
 *
 * Compile-time validation: `self::bootKernel()` triggers Symfony's full
 * compilation pass over services.yaml + autowiring. If
 * `OcrTextExtractionStrategy` (the real consumer of `LlmClientInterface`
 * since Pas 2.5.7) had a wrong typehint, missing bind, or unresolvable
 * dependency, the kernel boot itself would fail — every test in this class
 * would error out before reaching its assertion. Successful boot is the
 * wiring assertion. We don't fetch the alias from the container directly
 * because Symfony's autowiring resolves and PRUNES interface service ids
 * after compilation; reaching them at runtime would require declaring them
 * `public: true` in a test-only override, which would make the test prove
 * the workaround rather than the production wiring.
 */
class AnthropicApiClientIntegrationTest extends KernelTestCase
{
    public function testKernelBootsWithFullLlmStackWired(): void
    {
        self::bootKernel();

        // If we got here, the compile pass resolved every typehint in:
        //   - AnthropicApiClient (HttpClient, env binds, Logger)
        //   - LlmClientInterface alias → AnthropicApiClient
        //   - OcrTextExtractionStrategy (consumer of LlmClientInterface)
        //   - DataExtractionService (consumer of the tagged strategy iterator)
        // Any breakage above would surface as a CompilerException at bootKernel().
        $this->assertTrue(self::$kernel->getContainer() !== null);
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
