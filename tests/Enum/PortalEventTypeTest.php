<?php

namespace App\Tests\Enum;

use App\Enum\PortalEventType;
use PHPUnit\Framework\TestCase;

class PortalEventTypeTest extends TestCase
{
    public function testAllCasesHaveLabels(): void
    {
        foreach (PortalEventType::cases() as $type) {
            $this->assertNotEmpty($type->label(), "PortalEventType::{$type->name} has no label");
        }
    }

    public function testExpectedValuesExist(): void
    {
        $expectedValues = [
            'hearing_scheduled',
            'hearing_completed',
            'ruling_issued',
            'appeal_filed',
            'case_info_update',
        ];

        $enumValues = array_map(fn(PortalEventType $t) => $t->value, PortalEventType::cases());

        foreach ($expectedValues as $value) {
            $this->assertContains($value, $enumValues, "Expected value '$value' missing from PortalEventType");
        }
    }

    public function testCanCreateFromValue(): void
    {
        $type = PortalEventType::from('hearing_scheduled');
        $this->assertSame(PortalEventType::HEARING_SCHEDULED, $type);
    }
}
