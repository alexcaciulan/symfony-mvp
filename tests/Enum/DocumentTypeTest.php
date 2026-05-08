<?php

namespace App\Tests\Enum;

use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

class DocumentTypeTest extends TestCase
{
    public function testNewLexRecoveryValuesPresent(): void
    {
        $this->assertSame(DocumentType::SOMATIE, DocumentType::from('somatie'));
        $this->assertSame(DocumentType::CERERE_OP, DocumentType::from('cerere_op'));
        $this->assertSame(DocumentType::OPIS, DocumentType::from('opis'));
        $this->assertSame(DocumentType::DOVADA_COMUNICARE, DocumentType::from('dovada_comunicare'));
        $this->assertSame(DocumentType::ACT_CONSTATATOR, DocumentType::from('act_constatator'));
        $this->assertSame(DocumentType::ANEXA, DocumentType::from('anexa'));
    }

    public function testKeptValues(): void
    {
        $this->assertSame(DocumentType::DOVADA, DocumentType::from('dovada'));
        $this->assertSame(DocumentType::CONTRACT, DocumentType::from('contract'));
        $this->assertSame(DocumentType::FACTURA, DocumentType::from('factura'));
        $this->assertSame(DocumentType::ALT_DOCUMENT, DocumentType::from('alt_document'));
    }

    public function testCererePdfRemoved(): void
    {
        $this->assertNull(DocumentType::tryFrom('cerere_pdf'));
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (DocumentType::cases() as $case) {
            $this->assertNotEmpty($case->label());
        }
    }
}
