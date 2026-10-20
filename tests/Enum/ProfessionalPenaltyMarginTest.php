<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ProfessionalPenaltyMargin;
use PHPUnit\Framework\TestCase;

final class ProfessionalPenaltyMarginTest extends TestCase
{
    public function testContractConcludedTheDayBeforeLaw72Of2013KeepsFourPoints(): void
    {
        $margin = ProfessionalPenaltyMargin::forClaim(new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2013-04-04'));

        self::assertSame(ProfessionalPenaltyMargin::OG_ART_3_PARA_2, $margin);
    }

    public function testContractConcludedOnTheDayLaw72Of2013CameIntoForceGetsEightPoints(): void
    {
        $margin = ProfessionalPenaltyMargin::forClaim(new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2013-04-05'));

        self::assertSame(ProfessionalPenaltyMargin::OG_ART_3_PARA_2_1, $margin);
    }

    public function testClaimDueBeforeLaw72Of2013KeepsFourPointsWithoutContractDate(): void
    {
        $margin = ProfessionalPenaltyMargin::forClaim(new \DateTimeImmutable('2013-04-04'), null);

        self::assertSame(ProfessionalPenaltyMargin::OG_ART_3_PARA_2, $margin);
    }

    public function testLegalBasisParametersCarryTheDateThatLaw72Of2013CameIntoForce(): void
    {
        // Monitorul Oficial nr. 182 of 02.04.2013, in force three days later.
        self::assertSame(['%law_72_in_force%' => '05.04.2013'], ProfessionalPenaltyMargin::legalBasisParameters());
    }
}
