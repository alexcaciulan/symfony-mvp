<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Domain exception thrown by {@see LlmClientInterface} implementations when
 * an LLM call cannot complete: HTTP error (4xx auth/quota, 5xx after retries),
 * malformed response (invalid JSON, missing required fields), or network
 * timeout. Pattern aligned with {@see \App\Service\Company\AnafLookupException}
 * and {@see \App\Service\Ocr\OcrException}.
 *
 * Carries the classification the caller cannot reconstruct on its own: whether
 * another attempt has a chance, and the provider status code when there was
 * one. Without it every failure reads as retriable, so a request the provider
 * rejects outright is replayed until the queue gives up, spending rate-limit
 * budget on each pass.
 */
final class LlmException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $transient = false,
        private readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
        private readonly bool $providerUnavailable = false,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /**
     * Transport or HTTP failure worth another attempt: outage, throttling,
     * timeout.
     */
    public static function transient(string $message, ?int $statusCode = null, ?\Throwable $previous = null): self
    {
        return new self($message, true, $statusCode, $previous);
    }

    /**
     * Whether retrying the identical request later could succeed. False for
     * anything the provider will reject the same way every time.
     */
    public function isTransient(): bool
    {
        return $this->transient;
    }

    /** HTTP status returned by the provider, null when the call never got one. */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Whether the provider refused for its own reasons (billing, capacity,
     * account state) rather than because the request was bad. Reported as an
     * operator-side problem so the document is never blamed.
     */
    public function isProviderUnavailable(): bool
    {
        return $this->providerUnavailable;
    }
}
