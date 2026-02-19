<?php

namespace App\Enum;

enum UserType: string
{
    case PF = 'pf';
    case PJ = 'pj';
    case AVOCAT = 'avocat';
    case ADMIN = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::PF => 'Persoană fizică',
            self::PJ => 'Persoană juridică',
            self::AVOCAT => 'Avocat',
            self::ADMIN => 'Administrator',
        };
    }
}
