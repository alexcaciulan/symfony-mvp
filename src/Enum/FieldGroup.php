<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The unit of aggregation across documents.
 *
 * Fields are picked in groups rather than one by one because the fields inside
 * a group only mean anything together: a name and a CUI read from two different
 * documents describe two different companies, and the pair of them describes a
 * company that does not exist. Choosing one source per group is what keeps the
 * party in the filing an entity some document actually names.
 *
 * Internal to the aggregation layer, so no label(): nothing renders a group.
 */
enum FieldGroup: string
{
    /** Who the party is: the fields a court identifies them by. */
    case PARTY_IDENTITY = 'PARTY_IDENTITY';

    /** Where to reach them, and the address competence is resolved from. */
    case PARTY_CONTACT = 'PARTY_CONTACT';

    /** Where money moves: IBAN and bank. */
    case PARTY_BANKING = 'PARTY_BANKING';

    /** What is owed and when it fell due, plus the invoice that states it. */
    case CLAIM_AMOUNT = 'CLAIM_AMOUNT';

    /** Why it is owed: the legal ground and the contract behind it. */
    case CLAIM_BASIS = 'CLAIM_BASIS';

    /** The penalty clause, which only a contract can establish. */
    case CLAIM_PENALTY = 'CLAIM_PENALTY';

    /**
     * Whether the fields in this group describe a party.
     *
     * Where they do, a value borrowed from another document can produce a
     * person who does not exist, so borrowing has to be proven rather than
     * merely uncontradicted.
     */
    public function isAboutAParty(): bool
    {
        return match ($this) {
            self::PARTY_IDENTITY, self::PARTY_CONTACT, self::PARTY_BANKING => true,
            self::CLAIM_AMOUNT, self::CLAIM_BASIS, self::CLAIM_PENALTY => false,
        };
    }

    /**
     * Party fields by group. Covers both parties: the creditor-only and
     * debtor-only fields are listed together because a field name is never
     * reused across roles with a different meaning.
     *
     * @return array<string, self>
     */
    public static function partyFieldMap(): array
    {
        return [
            'personType' => self::PARTY_IDENTITY,
            'name' => self::PARTY_IDENTITY,
            'cui' => self::PARTY_IDENTITY,
            'personalId' => self::PARTY_IDENTITY,
            'onrcNumber' => self::PARTY_IDENTITY,
            'address' => self::PARTY_CONTACT,
            'county' => self::PARTY_CONTACT,
            'locality' => self::PARTY_CONTACT,
            'email' => self::PARTY_CONTACT,
            'phone' => self::PARTY_CONTACT,
            'legalRepresentative' => self::PARTY_CONTACT,
            'administrator' => self::PARTY_CONTACT,
            'iban' => self::PARTY_BANKING,
            'bankName' => self::PARTY_BANKING,
        ];
    }

    /**
     * Claim fields by group.
     *
     * The invoice identifiers sit with the amount on purpose: the number and
     * the date of the invoice are what makes the sum attributable, and reading
     * the sum from one invoice and the number from another produces a position
     * that names the wrong document in the petition.
     *
     * @return array<string, self>
     */
    public static function claimFieldMap(): array
    {
        return [
            'amount' => self::CLAIM_AMOUNT,
            'currency' => self::CLAIM_AMOUNT,
            'dueDate' => self::CLAIM_AMOUNT,
            'invoiceNumber' => self::CLAIM_AMOUNT,
            'invoiceDate' => self::CLAIM_AMOUNT,
            'legalGround' => self::CLAIM_BASIS,
            'description' => self::CLAIM_BASIS,
            'contractNumber' => self::CLAIM_BASIS,
            'contractDate' => self::CLAIM_BASIS,
            'contractReference' => self::CLAIM_BASIS,
            'penaltyType' => self::CLAIM_PENALTY,
            'contractualPenaltyRate' => self::CLAIM_PENALTY,
        ];
    }
}
