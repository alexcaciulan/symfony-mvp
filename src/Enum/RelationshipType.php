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
     * The rate (%) applicable over a given NBR reference rate, per OG 13/2011.
     *
     * Scope is B2B only (professional to professional):
     *   - COMERCIAL + PENALIZATOARE: NBR + the margin of the contract (art. 3
     *     para. 2 or para. 2^1, see {@see ProfessionalPenaltyMargin})
     *   - COMERCIAL + REMUNERATORIE: NBR (art. 3 para. 1), no margin
     *
     * @throws \DomainException for non-professional relations (CIVIL)
     */
    public function applicableRate(float $nbrRate, InterestKind $kind, ProfessionalPenaltyMargin $margin): float
    {
        return match (true) {
            $this === self::COMERCIAL && $kind === InterestKind::PENALIZATOARE => $nbrRate + $margin->points(),
            $this === self::COMERCIAL && $kind === InterestKind::REMUNERATORIE => $nbrRate,
            $this === self::CIVIL => throw new \DomainException(
                'Raporturile non-profesionale (CIVIL) nu sunt suportate în MVP.'
                . ' Scope curent: B2B exclusiv (OG 13/2011 art. 3 alin. 1 + 2¹).'
                . ' Pentru B2C/P2P, vezi backlog post-MVP (Opțiunea b din PLAN Pas 2.1 revizie 2026-05-09).'
            ),
        };
    }
}
