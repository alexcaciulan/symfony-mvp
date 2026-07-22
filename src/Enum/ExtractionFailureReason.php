<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why an extraction produced no data.
 *
 * Without this, every failure mode collapses into a single zero-confidence
 * result and the lawyer sees the same badge for an operations problem (no API
 * key) and for something they can act on (file too large). Persisted on
 * {@see \App\Entity\Document} so the UI can show the cause and the message
 * handler can tell a retriable failure from a permanent one.
 */
enum ExtractionFailureReason: string
{
    case API_UNAVAILABLE = 'API_UNAVAILABLE';
    case RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    case FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    case FILE_UNREADABLE = 'FILE_UNREADABLE';
    case RESPONSE_TRUNCATED = 'RESPONSE_TRUNCATED';
    case RESPONSE_MALFORMED = 'RESPONSE_MALFORMED';
    case UNSUPPORTED_MIME = 'UNSUPPORTED_MIME';
    case LOCAL_ONLY_MODE = 'LOCAL_ONLY_MODE';

    /**
     * No AI processing agreement on the account. Distinct from LOCAL_ONLY_MODE:
     * the lawyer has not been asked yet, rather than having chosen local-only.
     */
    case AGREEMENT_MISSING = 'AGREEMENT_MISSING';

    /**
     * The installation has no API credentials. Retrying changes nothing until
     * an operator sets the key, so this must not be treated as transient.
     */
    case API_KEY_MISSING = 'API_KEY_MISSING';

    /**
     * The provider answered with a permanent client error (bad request, unknown
     * model, payload refused). Retrying replays the same rejection and spends
     * rate-limit budget for nothing.
     */
    case PROVIDER_REJECTED = 'PROVIDER_REJECTED';

    /**
     * The provider is temporarily unable to serve us for a reason that has
     * nothing to do with the document: exhausted billing credits, an overloaded
     * endpoint answering 4xx, a suspended account. The lawyer's file is fine, so
     * the message must not tell them to fix it; topping up or waiting clears it.
     */
    case PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';

    public function label(): string
    {
        return 'enum.extraction_failure_reason.' . $this->value;
    }

    /**
     * Whether retrying the same document later has a realistic chance of
     * succeeding. Drives both the Messenger retry decision and whether the UI
     * offers a "retry extraction" button.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::API_UNAVAILABLE, self::RATE_LIMIT_EXCEEDED => true,
            // An answer the reader could not decode is a sampling accident, not
            // a property of the document: the same file re-read usually comes
            // back fine, which is exactly what was observed when two of three
            // identically shaped invoices failed and the third did not. Without
            // this the lawyer is offered no retry and the only way forward is
            // to correct the document type by hand and hope.
            self::RESPONSE_MALFORMED => true,
            // Not the document's fault and clears once the provider is topped up
            // or recovers, so the lawyer gets a retry button and an honest "our
            // side" message rather than being told to fix their file.
            self::PROVIDER_UNAVAILABLE => true,
            default => false,
        };
    }

    /**
     * Whether the cause sits with the platform (its billing, its key, its
     * policy) rather than the uploaded file. Used to word the message so an
     * operator problem is never dressed up as a bad document.
     */
    public function isOperatorSide(): bool
    {
        return match ($this) {
            self::PROVIDER_UNAVAILABLE, self::API_UNAVAILABLE,
            self::API_KEY_MISSING, self::RATE_LIMIT_EXCEEDED => true,
            default => false,
        };
    }
}
