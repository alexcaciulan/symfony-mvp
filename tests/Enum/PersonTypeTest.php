<?php

namespace App\Tests\Enum;

use App\Enum\PersonType;
use PHPUnit\Framework\TestCase;

class PersonTypeTest extends TestCase
{
    public function testHasTwoCases(): void
    {
        $this->assertCount(2, PersonType::cases());
    }

    public function testLabels(): void
    {
        $this->assertSame('enum.person_type.PF', PersonType::PF->label());
        $this->assertSame('enum.person_type.PJ', PersonType::PJ->label());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(PersonType::PF, PersonType::from('PF'));
        $this->assertSame(PersonType::PJ, PersonType::from('PJ'));
    }
}
