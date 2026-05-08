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

    /**
     * Returns the rate (%) applicable to a given BNR reference rate, per OG 13/2011 art. 3.
     *
     * - COMERCIAL + PENALIZATOARE: BNR + 8 (alin. 2¹)
     * - CIVIL      + PENALIZATOARE: (BNR + 8) × 0.80 (alin. 3 — diminuat 20% din comercial)
     * - COMERCIAL + REMUNERATORIE: BNR (alin. 2)
     * - CIVIL      + REMUNERATORIE: BNR × 0.80 (alin. 3 aplicat la remuneratoriu)
     */
    public function applicableRate(float $nbrRate, InterestKind $kind): float
    {
        $base = match ($kind) {
            InterestKind::PENALIZATOARE => $nbrRate + 8.0,
            InterestKind::REMUNERATORIE => $nbrRate,
        };

        return match ($this) {
            self::COMERCIAL => $base,
            self::CIVIL => $base * 0.80,
        };
    }
}
