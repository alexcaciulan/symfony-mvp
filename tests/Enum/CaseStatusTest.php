<?php

namespace App\Tests\Enum;

use App\Enum\CaseStatus;
use PHPUnit\Framework\TestCase;

class CaseStatusTest extends TestCase
{
    public function testAllCasesHaveLabels(): void
    {
        foreach (CaseStatus::cases() as $status) {
            $this->assertNotEmpty($status->label(), "CaseStatus::{$status->name} has no label");
        }
    }

    public function testValuesMatchWorkflowPlaces(): void
    {
        $expectedPlaces = [
            'draft', 'pending_payment', 'paid', 'submitted_to_court',
            'under_review', 'additional_info_requested',
            'resolved_accepted', 'resolved_rejected', 'enforcement',
        ];

        $enumValues = array_map(fn(CaseStatus $s) => $s->value, CaseStatus::cases());

        foreach ($expectedPlaces as $place) {
            $this->assertContains($place, $enumValues, "Workflow place '$place' missing from CaseStatus enum");
        }
    }
}
