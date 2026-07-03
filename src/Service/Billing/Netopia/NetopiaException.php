<?php

declare(strict_types=1);

namespace App\Service\Billing\Netopia;

/**
 * Domain exception thrown by {@see NetopiaApiClient} when a Netopia API call
 * cannot complete: HTTP error, malformed response, or a failed IPN signature
 * verification. Pattern aligned with {@see App\Service\Llm\LlmException}.
 *
 * `$retryable` marks transient failures (network/5xx) the async webhook handler
 * may re-throw for Messenger to retry; business failures (4xx, bad signature)
 * are terminal.
 */
final class NetopiaException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
