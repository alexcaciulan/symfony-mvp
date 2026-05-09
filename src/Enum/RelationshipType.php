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
     * Returns the rate (%) applicable to a given BNR reference rate, per OG 13/2011.
     *
     * MVP scope: B2B exclusiv (raporturi profesionist ↔ profesionist).
     *   - COMERCIAL + PENALIZATOARE: BNR + 8  (art. 3 alin. 2¹ introdus prin L. 72/2013 art. 20)
     *   - COMERCIAL + REMUNERATORIE: BNR       (art. 3 alin. 1)
     *
     * Out of scope MVP (B2C / P2P): backlog post-MVP — vezi Opțiunea (b)
     * din PLAN-DEZVOLTARE-LEXRECOVERY.md, Pas 2.1 revizie 2026-05-09.
     *
     * @throws \DomainException pentru raporturi non-profesionale (CIVIL).
     */
    public function applicableRate(float $nbrRate, InterestKind $kind): float
    {
        return match (true) {
            $this === self::COMERCIAL && $kind === InterestKind::PENALIZATOARE => $nbrRate + 8.0,
            $this === self::COMERCIAL && $kind === InterestKind::REMUNERATORIE => $nbrRate,
            $this === self::CIVIL => throw new \DomainException(
                'Raporturile non-profesionale (CIVIL) nu sunt suportate în MVP.'
                . ' Scope curent: B2B exclusiv (OG 13/2011 art. 3 alin. 1 + 2¹).'
                . ' Pentru B2C/P2P, vezi backlog post-MVP (Opțiunea b din PLAN Pas 2.1 revizie 2026-05-09).'
            ),
        };
    }
}
