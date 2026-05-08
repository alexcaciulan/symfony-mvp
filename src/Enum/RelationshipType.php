<?php

namespace App\Enum;

enum RelationshipType: string
{
    case COMERCIAL = 'COMERCIAL';
    case CIVIL = 'CIVIL';

    public function label(): string
    {
        return 'enum.relationship_type.' . $this->value;
    }

    public function nbrPercentagePoints(): int
    {
        return match ($this) {
            self::COMERCIAL => 8,
            self::CIVIL => 4,
        };
    }
}
