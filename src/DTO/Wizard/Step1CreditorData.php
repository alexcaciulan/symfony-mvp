<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\PersonType;
use App\Validator\Constraints\ValidCnp;
use App\Validator\Constraints\ValidCui;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wizard step 1 — Creditor.
 *
 * Public mutable properties so the form can bind directly via `data_class`.
 * `autoFilled` carries the list of field names that the prefill aggregator
 * populated from extracted documents — the form renders a badge "auto · N%"
 * for each of those.
 *
 * The class-level `Assert\Expression` enforces the xor between (a) selecting
 * an existing creditor by id and (b) filling the manual fields. When
 * `creditorId` is provided we trust the autocompletion path (the entity is
 * loaded in Pas 3.2 controller); otherwise `personType`, `name`, `address`
 * are mandatory.
 */
#[Assert\Expression(
    expression: 'this.creditorId !== null or (this.personType !== null and this.name !== null and this.address !== null)',
    message: 'wizard.step1.error.either_id_or_manual',
)]
class Step1CreditorData
{
    /** @param list<string> $autoFilled */
    public function __construct(
        public ?int $creditorId = null,
        public ?PersonType $personType = null,
        public ?string $name = null,
        #[ValidCui]
        public ?string $cui = null,
        #[ValidCnp]
        public ?string $personalId = null,
        public ?string $onrcNumber = null,
        public ?string $address = null,
        #[Assert\Email(message: 'validation.email.invalid')]
        public ?string $email = null,
        public ?string $phone = null,
        // IBAN accepted with optional spaces (BCR/BT statements often show
        // RO49 RNCB 0082 ...). Strip + upper before regex via normalizer in
        // Step1CreditorType. The compiled value (no spaces, upper) matches
        // \d{16} on the account part — the [A-Z0-9]{16} alternative would
        // accept letters in the account portion which RO IBANs never have.
        #[Assert\Regex(
            pattern: '/^RO\d{2}[A-Z]{4}\d{16}$/',
            message: 'validation.iban.invalid_format',
        )]
        public ?string $iban = null,
        public ?string $legalRepresentative = null,
        public array $autoFilled = [],
    ) {}
}
