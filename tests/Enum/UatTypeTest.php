<?php

namespace App\Tests\Enum;

use App\Enum\UatType;
use PHPUnit\Framework\TestCase;

class UatTypeTest extends TestCase
{
    public function testHasFourCases(): void
    {
        $this->assertCount(4, UatType::cases());
    }

    public function testLabels(): void
    {
        $this->assertSame('enum.uat_type.municipiu', UatType::MUNICIPIU->label());
        $this->assertSame('enum.uat_type.oras', UatType::ORAS->label());
        $this->assertSame('enum.uat_type.comuna', UatType::COMUNA->label());
        $this->assertSame('enum.uat_type.sector', UatType::SECTOR->label());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(UatType::MUNICIPIU, UatType::from('municipiu'));
        $this->assertSame(UatType::ORAS, UatType::from('oras'));
        $this->assertSame(UatType::COMUNA, UatType::from('comuna'));
        $this->assertSame(UatType::SECTOR, UatType::from('sector'));
    }
}
