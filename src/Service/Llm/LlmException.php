<?php

namespace App\Service\Llm;

/**
 * Domain exception thrown by {@see LlmClientInterface} implementations when
 * an LLM call cannot complete: HTTP error (4xx auth/quota, 5xx after retries),
 * malformed response (invalid JSON, missing required fields), or network
 * timeout. Pattern aligned with {@see App\Service\Company\AnafLookupException}
 * and {@see App\Service\Ocr\OcrException}.
 */
final class LlmException extends \RuntimeException
{
}
