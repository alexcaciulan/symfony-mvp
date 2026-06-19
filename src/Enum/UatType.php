<?php

namespace App\Enum;

/**
 * Administrative-territorial unit type for a City (UAT level).
 * Romanian local government tiers plus Bucharest sectors.
 */
enum UatType: string
{
    case MUNICIPIU = 'municipiu';
    case ORAS = 'oras';
    case COMUNA = 'comuna';
    case SECTOR = 'sector';

    public function label(): string
    {
        return 'enum.uat_type.' . $this->value;
    }
}
