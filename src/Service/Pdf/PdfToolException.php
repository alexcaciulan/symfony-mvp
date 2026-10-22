<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * A PDF tool refused a file or failed. Carries a translation key so the lawyer
 * is told which document and why, not the tool's stderr.
 */
final class PdfToolException extends \RuntimeException
{
    /**
     * @param array<string, string> $messageParams
     */
    public function __construct(
        string $message,
        public readonly string $messageKey = 'filing.package.error.tool_failed',
        public readonly array $messageParams = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function forDocument(string $documentName): self
    {
        return new self($this->getMessage(), $this->messageKey, [...$this->messageParams, '%document%' => $documentName], $this);
    }
}
