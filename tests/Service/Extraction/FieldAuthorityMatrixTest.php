<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\Enum\DocumentType;
use App\Enum\FieldGroup;
use App\Service\Extraction\FieldAuthorityMatrix;
use PHPUnit\Framework\TestCase;

final class FieldAuthorityMatrixTest extends TestCase
{
    private FieldAuthorityMatrix $matrix;

    protected function setUp(): void
    {
        $this->matrix = new FieldAuthorityMatrix();
    }

    public function testAnInvoiceOutranksAContractOnTheSumOwed(): void
    {
        // A contract states a price that may never have been invoiced. Letting
        // it set the principal moves the stamp duty and the competent court.
        self::assertGreaterThan(
            $this->matrix->weight(DocumentType::CONTRACT, FieldGroup::CLAIM_AMOUNT),
            $this->matrix->weight(DocumentType::FACTURA, FieldGroup::CLAIM_AMOUNT),
        );
    }

    public function testAContractOutranksAnInvoiceOnThePenaltyClause(): void
    {
        // A rate printed on an invoice footer is the supplier's assertion, not
        // a clause the debtor agreed to.
        self::assertGreaterThan(
            $this->matrix->weight(DocumentType::FACTURA, FieldGroup::CLAIM_PENALTY),
            $this->matrix->weight(DocumentType::CONTRACT, FieldGroup::CLAIM_PENALTY),
        );
    }

    public function testAContractOutranksAnInvoiceOnPartyIdentity(): void
    {
        self::assertGreaterThan(
            $this->matrix->weight(DocumentType::FACTURA, FieldGroup::PARTY_IDENTITY),
            $this->matrix->weight(DocumentType::CONTRACT, FieldGroup::PARTY_IDENTITY),
        );
    }

    public function testABankStatementOutranksEverythingOnBankingDetails(): void
    {
        foreach ([DocumentType::FACTURA, DocumentType::CONTRACT, DocumentType::NOTIFICARE] as $other) {
            self::assertGreaterThan(
                $this->matrix->weight($other, FieldGroup::PARTY_BANKING),
                $this->matrix->weight(DocumentType::EXTRAS_CONT, FieldGroup::PARTY_BANKING),
                $other->value . ' must not outrank a bank statement on banking details',
            );
        }
    }

    public function testABankStatementIsEvidenceAgainstItselfOnTheSumClaimed(): void
    {
        // It proves payment, never the debt.
        self::assertLessThan(0, $this->matrix->weight(DocumentType::EXTRAS_CONT, FieldGroup::CLAIM_AMOUNT));
    }

    public function testAnUndeclaredDocumentSitsAtTheNeutralRank(): void
    {
        // Most uploads are undeclared until a detection is adopted. Starving
        // them would mean prefilling nothing in the common case.
        foreach (FieldGroup::cases() as $group) {
            self::assertSame(0, $this->matrix->weight(null, $group), $group->value);
            self::assertSame(0, $this->matrix->weight(DocumentType::ALT_DOCUMENT, $group), $group->value);
        }
    }
}
