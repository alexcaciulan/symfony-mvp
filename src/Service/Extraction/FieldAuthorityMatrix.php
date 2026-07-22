<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\Enum\DocumentType;
use App\Enum\FieldGroup;

/**
 * How much a kind of document is worth on a kind of field.
 *
 * Different documents put semantically different things in the same field. An
 * invoice states what is owed; a contract states a price that may never have
 * been invoiced; a bank statement states what was paid. Picking between them on
 * the model's self-reported confidence alone lets a contract's total price
 * become the principal of the filing, which moves the stamp duty and the
 * competent court with it.
 *
 * This generalises the flat claim ranking the prefill service used before: the
 * ranking is now per (document type, field group) rather than per document, so
 * the contract can outrank the invoice on the penalty clause while the invoice
 * outranks it on the sum.
 */
final class FieldAuthorityMatrix
{
    /**
     * The rank of a document that declares nothing. Most uploads are
     * undeclared until a classification is adopted, and starving them would
     * mean prefilling nothing in the common case.
     */
    private const NEUTRAL = 0;

    /**
     * @return int higher wins; negative means the document is evidence against
     *         being read for this group at all
     */
    public function weight(?DocumentType $type, FieldGroup $group): int
    {
        if ($type === null) {
            return self::NEUTRAL;
        }

        return match ($group) {
            FieldGroup::PARTY_IDENTITY => $this->partyIdentityWeight($type),
            FieldGroup::PARTY_CONTACT => $this->partyContactWeight($type),
            FieldGroup::PARTY_BANKING => $this->partyBankingWeight($type),
            FieldGroup::CLAIM_AMOUNT => $this->claimAmountWeight($type),
            FieldGroup::CLAIM_BASIS => $this->claimBasisWeight($type),
            FieldGroup::CLAIM_PENALTY => $this->claimPenaltyWeight($type),
        };
    }

    /**
     * Identity is best stated where the parties signed. A contract names them
     * in full, with registration numbers, because that is what makes it
     * enforceable; an invoice header abbreviates.
     */
    private function partyIdentityWeight(DocumentType $type): int
    {
        return match ($type) {
            DocumentType::CONTRACT, DocumentType::ACT_ADITIONAL => 3,
            DocumentType::FACTURA, DocumentType::CONFIRMARE_SOLD => 2,
            DocumentType::SOMATIE_ANTERIOARA, DocumentType::NOTIFICARE, DocumentType::TITLU_VALOARE => 1,
            // A statement names an account holder, often truncated to what the
            // bank prints, and names the other party only as a payment label.
            DocumentType::EXTRAS_CONT => -1,
            default => self::NEUTRAL,
        };
    }

    /**
     * The debtor's address decides the competent court, so the document that
     * carries the address the parties agreed to be reached at wins over the one
     * carrying a delivery address.
     */
    private function partyContactWeight(DocumentType $type): int
    {
        return match ($type) {
            DocumentType::CONTRACT, DocumentType::ACT_ADITIONAL => 3,
            DocumentType::FACTURA => 2,
            DocumentType::SOMATIE_ANTERIOARA, DocumentType::NOTIFICARE, DocumentType::CONFIRMARE_SOLD => 1,
            DocumentType::EXTRAS_CONT => -1,
            default => self::NEUTRAL,
        };
    }

    /**
     * The account is stated best by the bank. On an invoice the IBAN is the
     * supplier's collection account, which is right but secondary; on a
     * contract it may be years out of date.
     */
    private function partyBankingWeight(DocumentType $type): int
    {
        return match ($type) {
            DocumentType::EXTRAS_CONT => 3,
            DocumentType::FACTURA => 2,
            DocumentType::CONTRACT, DocumentType::ACT_ADITIONAL => 1,
            default => self::NEUTRAL,
        };
    }

    /**
     * The sum claimed and the date it fell due. Only documents that establish
     * an exigible debt speak here; a contract price is not a debt.
     */
    private function claimAmountWeight(DocumentType $type): int
    {
        return match ($type) {
            DocumentType::FACTURA, DocumentType::CONFIRMARE_SOLD => 3,
            DocumentType::TITLU_VALOARE => 2,
            DocumentType::SOMATIE_ANTERIOARA, DocumentType::NOTIFICARE => 1,
            // Proof of payment, never of the sum claimed.
            DocumentType::EXTRAS_CONT => -1,
            default => self::NEUTRAL,
        };
    }

    /**
     * The legal ground, and the contract the claim rests on. This is the
     * contract's own subject matter.
     */
    private function claimBasisWeight(DocumentType $type): int
    {
        return match ($type) {
            DocumentType::CONTRACT, DocumentType::ACT_ADITIONAL => 3,
            DocumentType::COMANDA, DocumentType::PROCES_VERBAL => 2,
            DocumentType::FACTURA, DocumentType::CONFIRMARE_SOLD => 1,
            default => self::NEUTRAL,
        };
    }

    /**
     * A penalty rate binds only where it was agreed. A rate printed on an
     * invoice footer is the supplier's assertion, not a clause, and a court can
     * be expected to say so.
     */
    private function claimPenaltyWeight(DocumentType $type): int
    {
        return match ($type) {
            DocumentType::CONTRACT, DocumentType::ACT_ADITIONAL => 3,
            DocumentType::COMANDA => 1,
            DocumentType::FACTURA, DocumentType::SOMATIE_ANTERIOARA, DocumentType::NOTIFICARE => -1,
            default => self::NEUTRAL,
        };
    }
}
