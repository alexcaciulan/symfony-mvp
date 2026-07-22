<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which extraction engine set a user's documents go through.
 *
 * LEGACY_CASCADE keeps the historical four-tier chain (PdfParser, OcrText,
 * AiVision, Stub). AI_ONLY runs AiVision alone, which is the column default so
 * that new accounts get it without any call-site change; existing rows are
 * pinned to LEGACY_CASCADE by the migration so their behaviour never shifts
 * under them.
 */
enum ExtractionPipeline: string
{
    case LEGACY_CASCADE = 'LEGACY_CASCADE';
    case AI_ONLY = 'AI_ONLY';

    public function label(): string
    {
        return 'enum.extraction_pipeline.' . $this->value;
    }
}
