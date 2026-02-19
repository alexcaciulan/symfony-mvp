<?php

namespace App\Enum;

enum CourtType: string
{
    case JUDECATORIE = 'judecatorie';
    case TRIBUNAL = 'tribunal';

    public function label(): string
    {
        return match ($this) {
            self::JUDECATORIE => 'Judecătorie',
            self::TRIBUNAL => 'Tribunal',
        };
    }
}
