<?php

namespace App\Enum;

enum CourtType: string
{
    case JUDECATORIE = 'judecatorie';
    case TRIBUNAL = 'tribunal';
    // Specialized commercial tribunal (Cluj/Mureș/Argeș, Legea 304/2022 art. 41).
    // Competent for B2B litigation between professionals in its county.
    case TRIBUNAL_SPECIALIZAT = 'tribunal_specializat';

    public function label(): string
    {
        return 'enum.court_type.' . $this->value;
    }
}
