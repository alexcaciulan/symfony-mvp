<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/** An uploaded PDF that passed UploadedPdfValidator, moved under a generated name. */
final readonly class ValidatedPdf
{
    public function __construct(
        public string $path,
        public int $bytes,
        public string $sha256,
        public int $pageCount,
    ) {}
}
