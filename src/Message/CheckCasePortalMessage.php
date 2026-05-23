<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async per-case message dispatched by `app:portal-check-all`. The handler
 * ({@see \App\MessageHandler\CheckCasePortalMessageHandler}) runs `monitorCase()`
 * in a worker; on a transient SOAP failure it lets the exception propagate so
 * Messenger retries (max 3, exponential backoff) per the `retry_strategy` in
 * messenger.yaml.
 *
 * The command staggers messages with a DelayStamp (2-5s apart) to avoid flooding
 * portal.just.ro with simultaneous requests.
 */
final readonly class CheckCasePortalMessage
{
    public function __construct(public int $caseId) {}
}
