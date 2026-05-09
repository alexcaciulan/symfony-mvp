<?php

namespace App\Tests\Enum;

use App\Enum\AnafStatus;
use PHPUnit\Framework\TestCase;

class AnafStatusTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        $this->assertCount(3, AnafStatus::cases());
    }

    public function testLabels(): void
    {
        $this->assertSame('enum.anaf_status.ACTIV', AnafStatus::ACTIV->label());
        $this->assertSame('enum.anaf_status.INACTIV', AnafStatus::INACTIV->label());
        $this->assertSame('enum.anaf_status.RADIAT', AnafStatus::RADIAT->label());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(AnafStatus::ACTIV, AnafStatus::from('ACTIV'));
        $this->assertSame(AnafStatus::INACTIV, AnafStatus::from('INACTIV'));
        $this->assertSame(AnafStatus::RADIAT, AnafStatus::from('RADIAT'));
    }
}
