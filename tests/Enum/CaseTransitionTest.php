<?php

namespace App\Tests\Enum;

use App\Enum\CaseTransition;
use PHPUnit\Framework\TestCase;

class CaseTransitionTest extends TestCase
{
    public function testHasSeventeenTransitions(): void
    {
        $this->assertCount(17, CaseTransition::cases());
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
        $this->assertSame(CaseTransition::TRECE_LA_EXECUTARE, CaseTransition::from('trece_la_executare'));
        $this->assertSame(CaseTransition::INCHIDE_FARA_RECUPERARE, CaseTransition::from('inchide_fara_recuperare'));
        $this->assertSame(CaseTransition::INCHIDE_PLATA_INTEGRALA, CaseTransition::from('inchide_plata_integrala'));
        $this->assertSame(CaseTransition::ANULEAZA_DEPUNERE_NELIVRATA, CaseTransition::from('anuleaza_depunere_nelivrata'));
        $this->assertSame('enum.case_transition.anuleaza_depunere_nelivrata', CaseTransition::ANULEAZA_DEPUNERE_NELIVRATA->label());
        $this->assertNull(CaseTransition::tryFrom('not_a_transition'));
        $this->assertNull(CaseTransition::tryFrom('inchide_insolvabil'));
    }
}
