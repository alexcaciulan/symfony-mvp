<?php

namespace App\Enum;

enum PersonType: string
{
    case PF = 'PF';
    case PJ = 'PJ';

    public function label(): string
    {
        return match ($this) {
            self::PF => 'Persoană fizică',
            self::PJ => 'Persoană juridică',
        };
    }
}
