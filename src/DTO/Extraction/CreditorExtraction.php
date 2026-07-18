<?php

namespace App\DTO\Extraction;

use App\Enum\PersonType;

final readonly class CreditorExtraction
{
    /**
     * @param ?bool                $isVatPayer         true if the source carried the `RO` prefix on the CUI (VAT payer
     *                                                 per Codul Fiscal art. 316); false if only the bare digits were present
     *                                                 (CIF, non-VAT-payer); null if the CUI itself wasn't extracted.
     * @param array<string, float> $confidencePerField field name → confidence score 0..1
     */
    public function __construct(
        public ?PersonType $personType = null,
        public ?string $name = null,
        public ?string $cui = null,
        public ?bool $isVatPayer = null,
        public ?string $personalId = null,
        public ?string $onrcNumber = null,
        public ?string $address = null,
        // County + locality select the town hall that collects the stamp duty
        // (OUG 80/2013 art. 40 alin. 1). Plain strings, not resolved against the
        // County/City nomenclature. Null when the document does not carry them.
        public ?string $county = null,
        public ?string $locality = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $iban = null,
        public ?string $legalRepresentative = null,
        public ?string $bankName = null,
        public array $confidencePerField = [],
    ) {}
}
