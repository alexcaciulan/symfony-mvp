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

    /**
     * The sector number when the name is a Bucharest sector as SIRUTA spells it
     * ("Sector 3"). Works on a bare name because some callers only keep the name,
     * such as the UAT snapshot taken when the stamp duty is paid.
     */
    public static function bucharestSectorNumber(?string $uatName): ?int
    {
        return $uatName !== null && preg_match('/^Sector ([1-6])$/', $uatName, $m) === 1 ? (int) $m[1] : null;
    }
}
