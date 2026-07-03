<?php

namespace App\Tests\Enum;

use App\Enum\CaseStatus;
use PHPUnit\Framework\TestCase;

class CaseStatusTest extends TestCase
{
    public function testHasTwelveCases(): void
    {
        $this->assertCount(12, CaseStatus::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (CaseStatus::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Missing label for {$case->name}");
        }
    }

    public function testAllCasesHaveColors(): void
    {
        foreach (CaseStatus::cases() as $case) {
            $this->assertNotEmpty($case->color(), "Missing color for {$case->name}");
        }
    }

    public function testIsTerminal(): void
    {
        $this->assertTrue(CaseStatus::RESPINSA->isTerminal());
        $this->assertTrue(CaseStatus::INCHIS_SUCCES->isTerminal());
        $this->assertTrue(CaseStatus::INCHIS_FARA_RECUPERARE->isTerminal());

        $this->assertFalse(CaseStatus::AMIABIL->isTerminal());
        $this->assertFalse(CaseStatus::DOSAR_INREGISTRAT->isTerminal());
        $this->assertFalse(CaseStatus::ORDONANTA_EMISA->isTerminal());
        $this->assertFalse(CaseStatus::DEFINITIVA->isTerminal());
        $this->assertFalse(CaseStatus::EXECUTARE->isTerminal());
    }

    public function testIsActiveOnPortal(): void
    {
        $this->assertTrue(CaseStatus::DOSAR_INREGISTRAT->isActiveOnPortal());
        $this->assertTrue(CaseStatus::TERMEN_FIXAT->isActiveOnPortal());
        $this->assertTrue(CaseStatus::ORDONANTA_EMISA->isActiveOnPortal());
        $this->assertTrue(CaseStatus::IN_ANULARE->isActiveOnPortal());

        $this->assertFalse(CaseStatus::AMIABIL->isActiveOnPortal());
        $this->assertFalse(CaseStatus::SOMATIE_TRIMISA->isActiveOnPortal());
        $this->assertFalse(CaseStatus::CERERE_DEPUSA->isActiveOnPortal());
        $this->assertFalse(CaseStatus::DEFINITIVA->isActiveOnPortal());
        $this->assertFalse(CaseStatus::EXECUTARE->isActiveOnPortal());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(CaseStatus::AMIABIL, CaseStatus::from('AMIABIL'));
        $this->assertSame(CaseStatus::SOMATIE_TRIMISA, CaseStatus::from('SOMATIE_TRIMISA'));
        $this->assertNull(CaseStatus::tryFrom('not_a_status'));
    }
}
