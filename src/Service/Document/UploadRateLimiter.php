<?php

declare(strict_types=1);

namespace App\Service\Document;

use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Two independent ceilings on document uploads, because the two abuses they
 * stop are different.
 *
 * `document_upload` is the extraction budget: one token per file that really
 * gets stored and sent to the LLM. A file recognised as already uploaded costs
 * nothing, since it costs no extraction either.
 *
 * `document_upload_request` bounds the bytes the server agrees to receive and
 * fingerprint, charged per file received whether it is kept or skipped. Without
 * it, replaying a batch of known files would be free traffic, and the reading
 * plus hashing of up to ten 10 MB uploads per request is not free.
 *
 * Keeping the arithmetic here (instead of inline in the controller) is what
 * makes the token counts assertable with real limiters, since the test
 * environment runs every configured limiter on the `no_limit` policy.
 */
final class UploadRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $documentUploadLimiter,
        private readonly RateLimiterFactory $documentUploadRequestLimiter,
    ) {}

    /**
     * Charges the traffic ceiling for a batch about to be read and hashed.
     * An empty batch still costs one token so that a request loop is bounded.
     */
    public function acceptsBatch(string $userIdentifier, int $receivedFiles): bool
    {
        return $this->documentUploadRequestLimiter
            ->create($userIdentifier)
            ->consume(max(1, $receivedFiles))
            ->isAccepted();
    }

    /**
     * Charges the extraction budget for the files that will be stored.
     * Nothing is consumed when the batch stored nothing.
     */
    public function acceptsExtractions(string $userIdentifier, int $storedFiles): bool
    {
        if ($storedFiles < 1) {
            return true;
        }

        return $this->documentUploadLimiter
            ->create($userIdentifier)
            ->consume($storedFiles)
            ->isAccepted();
    }
}
