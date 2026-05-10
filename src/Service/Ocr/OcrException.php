<?php

namespace App\Service\Ocr;

/**
 * Domain exception thrown by {@see OcrServiceInterface} implementations when
 * OCR cannot complete: missing binary, unsupported MIME, unreadable file,
 * non-zero exit code from the underlying tool, or empty output for an input
 * that should have produced text. Pattern aligned with
 * {@see App\Service\Company\AnafLookupException}.
 */
class OcrException extends \RuntimeException
{
}
