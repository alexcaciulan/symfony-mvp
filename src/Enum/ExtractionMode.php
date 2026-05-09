<?php

namespace App\Enum;

enum ExtractionMode: string
{
    case LOCAL_ONLY = 'LOCAL_ONLY';
    case BALANCED = 'BALANCED';
    case MAX_ACCURACY = 'MAX_ACCURACY';

    public function label(): string
    {
        return 'enum.extraction_mode.' . $this->value;
    }

    /**
     * Whether AI-backed extraction strategies (OcrText with Claude, AiVision)
     * are allowed for this mode.
     *
     * LOCAL_ONLY enforces GDPR data minimisation (privacy by default,
     * art. 25 GDPR) — AI strategies are skipped by the orchestrator.
     */
    public function isAiAllowed(): bool
    {
        return match ($this) {
            self::LOCAL_ONLY => false,
            self::BALANCED, self::MAX_ACCURACY => true,
        };
    }
}
