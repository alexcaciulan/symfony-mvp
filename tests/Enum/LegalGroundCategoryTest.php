<?php

namespace App\Tests\Enum;

use App\Enum\LegalGroundCategory;
use PHPUnit\Framework\TestCase;

class LegalGroundCategoryTest extends TestCase
{
    public function testHasNineCases(): void
    {
        $this->assertCount(9, LegalGroundCategory::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (LegalGroundCategory::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Missing label for {$case->name}");
            $this->assertStringStartsWith('enum.legal_ground_category.', $case->label());
        }
    }

    public function testAllCasesAreOpEligible(): void
    {
        foreach (LegalGroundCategory::cases() as $case) {
            $this->assertTrue($case->isOpEligible(), "Expected {$case->name} eligible per CPC art. 1014");
        }
    }

    public function testDirectlyEnforceableInstrumentsAreCecCambieBilet(): void
    {
        $this->assertTrue(LegalGroundCategory::CEC->isDirectlyEnforceable());
        $this->assertTrue(LegalGroundCategory::CAMBIE->isDirectlyEnforceable());
        $this->assertTrue(LegalGroundCategory::BILET_LA_ORDIN->isDirectlyEnforceable());
    }

    public function testContractsAndInvoicesAreNotDirectlyEnforceable(): void
    {
        $this->assertFalse(LegalGroundCategory::CONTRACT_VANZARE->isDirectlyEnforceable());
        $this->assertFalse(LegalGroundCategory::CONTRACT_PRESTARI_SERVICII->isDirectlyEnforceable());
        $this->assertFalse(LegalGroundCategory::CONTRACT_LOCATIUNE->isDirectlyEnforceable());
        $this->assertFalse(LegalGroundCategory::CONTRACT_IMPRUMUT->isDirectlyEnforceable());
        $this->assertFalse(LegalGroundCategory::FACTURA_ACCEPTATA->isDirectlyEnforceable());
        $this->assertFalse(LegalGroundCategory::ALTE_INSCRISURI->isDirectlyEnforceable());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(LegalGroundCategory::CONTRACT_VANZARE, LegalGroundCategory::from('CONTRACT_VANZARE'));
        $this->assertSame(LegalGroundCategory::FACTURA_ACCEPTATA, LegalGroundCategory::from('FACTURA_ACCEPTATA'));
        $this->assertNull(LegalGroundCategory::tryFrom('NOT_A_CATEGORY'));
    }
}
