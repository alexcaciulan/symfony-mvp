<?php

namespace App\Tests\Enum;

use App\Enum\CaseTransition;
use PHPUnit\Framework\TestCase;

class CaseTransitionTest extends TestCase
{
    public function testAllCasesHaveLabels(): void
    {
        foreach (CaseTransition::cases() as $transition) {
            $this->assertNotEmpty($transition->label(), "CaseTransition::{$transition->name} has no label");
        }
    }

    public function testValuesMatchWorkflowTransitions(): void
    {
        $expectedTransitions = [
            'submit', 'confirm_payment', 'submit_to_court', 'mark_received',
            'request_info', 'provide_info', 'accept', 'reject', 'enforce',
        ];

        $enumValues = array_map(fn(CaseTransition $t) => $t->value, CaseTransition::cases());

        foreach ($expectedTransitions as $transition) {
            $this->assertContains($transition, $enumValues, "Workflow transition '$transition' missing from CaseTransition enum");
        }
    }
}
