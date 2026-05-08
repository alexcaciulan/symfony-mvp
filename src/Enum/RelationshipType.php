<?php

namespace App\Enum;

enum RelationshipType: string
{
    case COMERCIAL = 'COMERCIAL';
    case CIVIL = 'CIVIL';

    public function label(): string
    {
        return match ($this) {
            self::COMERCIAL => 'Comercial (între profesioniști)',
            self::CIVIL => 'Civil',
        };
    }

    public function nbrPercentagePoints(): int
    {
        return match ($this) {
            self::COMERCIAL => 8,
            self::CIVIL => 4,
        };
    }
}
