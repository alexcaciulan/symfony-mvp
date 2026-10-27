<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/** What QpdfRunner found in a PDF it accepted. */
final readonly class PdfInspection
{
    public function __construct(
        public int $pageCount,
        public bool $hasAcroForm,
    ) {}
}
