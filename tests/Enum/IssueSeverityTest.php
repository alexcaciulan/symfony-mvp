<?php

namespace App\Tests\Enum;

use App\Enum\IssueSeverity;
use PHPUnit\Framework\TestCase;

class IssueSeverityTest extends TestCase
{
    public function testHasTwoCases(): void
    {
        $this->assertCount(2, IssueSeverity::cases());
    }

    public function testLabels(): void
    {
        $this->assertSame('enum.issue_severity.ERROR', IssueSeverity::ERROR->label());
        $this->assertSame('enum.issue_severity.WARNING', IssueSeverity::WARNING->label());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(IssueSeverity::ERROR, IssueSeverity::from('ERROR'));
        $this->assertSame(IssueSeverity::WARNING, IssueSeverity::from('WARNING'));
    }
}
