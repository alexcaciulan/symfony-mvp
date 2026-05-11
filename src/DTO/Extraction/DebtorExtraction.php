<?php

namespace App\DTO\Extraction;

use App\Enum\PersonType;

final readonly class DebtorExtraction
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
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $iban = null,
        public ?string $administrator = null,
        public array $confidencePerField = [],
    ) {}
}
