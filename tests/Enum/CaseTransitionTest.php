<?php

namespace App\Tests\Enum;

use App\Enum\CaseTransition;
use PHPUnit\Framework\TestCase;

class CaseTransitionTest extends TestCase
{
    public function testHasTwelveTransitions(): void
    {
        $this->assertCount(12, CaseTransition::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (CaseTransition::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Missing label for {$case->name}");
        }
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(CaseTransition::TRIMITE_SOMATIE, CaseTransition::from('trimite_somatie'));
        $this->assertSame(CaseTransition::INREGISTREAZA_DOSAR, CaseTransition::from('inregistreaza_dosar'));
        $this->assertSame(CaseTransition::INCHIDE_INSOLVABIL, CaseTransition::from('inchide_insolvabil'));
        $this->assertNull(CaseTransition::tryFrom('not_a_transition'));
    }
}
