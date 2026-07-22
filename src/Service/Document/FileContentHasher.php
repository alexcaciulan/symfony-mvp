<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * Content fingerprint of an uploaded file.
 *
 * Kept apart from DocumentUploadService because two callers need the exact
 * same value at different moments: the upload path stamps it on the stored
 * Document, while the wizard needs it on the still-temporary file to decide
 * whether storing is worth it at all. One algorithm, one implementation.
 *
 * sha256 hex is 64 chars, which is what document.content_hash is sized for.
 */
final class FileContentHasher
{
    public const ALGORITHM = 'sha256';

    /**
     * Returns null when the path is not a readable file: callers treat that as
     * "unknown content" and fall through to their normal validation, rather
     * than failing an upload over a fingerprint that is only an optimisation.
     */
    public function hashFile(string $absolutePath): ?string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $hash = @hash_file(self::ALGORITHM, $absolutePath);

        return $hash === false ? null : $hash;
    }

    public function hashContents(string $contents): string
    {
        return hash(self::ALGORITHM, $contents);
    }
}
