<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Margin over the NBR reference rate for legal penalty interest between
 * professionals (OG 13/2011).
 *
 * Law 72/2013 art. 20 added art. 3 para. 2^1, raising the margin from 4 to
 * 8 percentage points, and its art. 21 keeps payment obligations from
 * contracts concluded before the law came into force out of its scope. Such
 * a contract stays under art. 3 para. 2 for the whole period the interest
 * runs, including after that date: the margin follows the contract, not the
 * calendar.
 *
 * Every text that names the cut-off date (summons, wizard hints) takes it
 * from here through {@see self::legalBasisParameters()}.
 */
enum ProfessionalPenaltyMargin: int
{
    case OG_ART_3_PARA_2 = 4;
    case OG_ART_3_PARA_2_1 = 8;

    /**
     * Published in Monitorul Oficial nr. 182 of 02.04.2013; the law sets no
     * date of its own, so it came into force three days after publication
     * (Constitution art. 78).
     */
    private const LAW_72_2013_IN_FORCE = '2013-04-05';

    /**
     * A claim that fell due before Law 72/2013 came into force necessarily
     * rests on an older contract, so its due date alone settles the margin
     * when the contract date is unknown.
     */
    public static function forClaim(\DateTimeImmutable $dueDate, ?\DateTimeImmutable $contractDate): self
    {
        $inForce = new \DateTimeImmutable(self::LAW_72_2013_IN_FORCE);

        $olderContract = $dueDate->setTime(0, 0, 0) < $inForce
            || ($contractDate !== null && $contractDate->setTime(0, 0, 0) < $inForce);

        return $olderContract ? self::OG_ART_3_PARA_2 : self::OG_ART_3_PARA_2_1;
    }

    public function points(): float
    {
        return (float) $this->value;
    }

    /**
     * Translation parameters for every text that names the date Law 72/2013
     * came into force.
     *
     * @return array{'%law_72_in_force%': string}
     */
    public static function legalBasisParameters(): array
    {
        return ['%law_72_in_force%' => (new \DateTimeImmutable(self::LAW_72_2013_IN_FORCE))->format('d.m.Y')];
    }

    /** Translation key of the provision that sets this margin. */
    public function legalBasis(): string
    {
        return 'enum.professional_penalty_margin.' . $this->name;
    }
}
