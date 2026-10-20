<?php

namespace App\Tests\Enum;

use App\Enum\InterestKind;
use App\Enum\ProfessionalPenaltyMargin;
use App\Enum\RelationshipType;
use PHPUnit\Framework\TestCase;

class RelationshipTypeTest extends TestCase
{
    public function testHasTwoCases(): void
    {
        $this->assertCount(2, RelationshipType::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (RelationshipType::cases() as $case) {
            $this->assertNotEmpty($case->label());
        }
    }

    public function testCommercialPenalizatoareRateIsBnrPlusEight(): void
    {
        $this->assertSame(
            14.0,
            RelationshipType::COMERCIAL->applicableRate(6.0, InterestKind::PENALIZATOARE, ProfessionalPenaltyMargin::OG_ART_3_PARA_2_1),
        );
    }

    public function testCommercialPenalizatoareRateUnderAContractBeforeLaw72Of2013IsBnrPlusFour(): void
    {
        $this->assertSame(
            10.0,
            RelationshipType::COMERCIAL->applicableRate(6.0, InterestKind::PENALIZATOARE, ProfessionalPenaltyMargin::OG_ART_3_PARA_2),
        );
    }

    public function testCommercialRemuneratorieRateIsBnr(): void
    {
        $this->assertSame(
            6.0,
            RelationshipType::COMERCIAL->applicableRate(6.0, InterestKind::REMUNERATORIE, ProfessionalPenaltyMargin::OG_ART_3_PARA_2_1),
        );
    }

    public function testCivilPenalizatoareThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/B2B exclusiv/');

        RelationshipType::CIVIL->applicableRate(6.0, InterestKind::PENALIZATOARE, ProfessionalPenaltyMargin::OG_ART_3_PARA_2_1);
    }

    public function testCivilRemuneratorieThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/B2B exclusiv/');

        RelationshipType::CIVIL->applicableRate(6.0, InterestKind::REMUNERATORIE, ProfessionalPenaltyMargin::OG_ART_3_PARA_2_1);
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(RelationshipType::COMERCIAL, RelationshipType::from('COMERCIAL'));
        $this->assertSame(RelationshipType::CIVIL, RelationshipType::from('CIVIL'));
    }
}
