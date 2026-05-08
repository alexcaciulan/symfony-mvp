<?php

namespace App\Tests\Enum;

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

    public function testNbrPercentagePoints(): void
    {
        $this->assertSame(8, RelationshipType::COMERCIAL->nbrPercentagePoints());
        $this->assertSame(4, RelationshipType::CIVIL->nbrPercentagePoints());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(RelationshipType::COMERCIAL, RelationshipType::from('COMERCIAL'));
        $this->assertSame(RelationshipType::CIVIL, RelationshipType::from('CIVIL'));
    }
}
