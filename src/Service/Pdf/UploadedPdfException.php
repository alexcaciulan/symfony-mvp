<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * An uploaded file the platform will not process. The message key is neutral and
 * technical: a refusal says nothing about the legal value of the document.
 */
final class UploadedPdfException extends \RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }
}
