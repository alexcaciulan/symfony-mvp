<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\Llm\LlmResponse;
use App\Service\Llm\LlmClientInterface;
use App\Service\Llm\LlmException;

/**
 * LLM client used in the test environment: refuses every call instead of
 * reaching the provider over the network.
 *
 * Without it, any test that runs the real cascade with an AI-allowed account
 * uploads the fixture document to Anthropic for real (the test API key is a
 * non-empty placeholder, so the strategy's key guard lets the call through) and
 * the outcome depends on network conditions. Refusing as a permanent rejection
 * keeps the result deterministic: the document ends FAILED, not queued for
 * retry. Tests that need a successful response inject their own double.
 *
 * Aliased over {@see LlmClientInterface} in config/packages/test/services.yaml.
 */
final class OfflineLlmClient implements LlmClientInterface
{
    public function complete(
        array $messages,
        int $maxTokens = 2048,
        ?array $documentParts = null,
        bool $cacheSystemPrompt = false,
        ?array $outputSchema = null,
    ): LlmResponse {
        throw new LlmException(
            'LLM calls are disabled in the test environment',
            transient: false,
            statusCode: 401,
        );
    }
}
