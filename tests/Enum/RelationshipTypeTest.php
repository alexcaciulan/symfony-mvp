<?php

namespace App\Tests\Enum;

use App\Enum\InterestKind;
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

    public function testApplicableRatePenalizatoare(): void
    {
        $this->assertSame(
            14.0,
            RelationshipType::COMERCIAL->applicableRate(6.0, InterestKind::PENALIZATOARE),
        );
        $this->assertEqualsWithDelta(
            (6.0 + 8.0) * 0.80,
            RelationshipType::CIVIL->applicableRate(6.0, InterestKind::PENALIZATOARE),
            0.0001,
        );
    }

    public function testApplicableRateRemuneratorie(): void
    {
        $this->assertSame(
            6.0,
            RelationshipType::COMERCIAL->applicableRate(6.0, InterestKind::REMUNERATORIE),
        );
        $this->assertEqualsWithDelta(
            6.0 * 0.80,
            RelationshipType::CIVIL->applicableRate(6.0, InterestKind::REMUNERATORIE),
            0.0001,
        );
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(RelationshipType::COMERCIAL, RelationshipType::from('COMERCIAL'));
        $this->assertSame(RelationshipType::CIVIL, RelationshipType::from('CIVIL'));
    }
}
