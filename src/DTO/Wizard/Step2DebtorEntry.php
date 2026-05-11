<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\AnafStatus;
use App\Enum\PersonType;
use App\Validator\Constraints\ValidCnp;
use App\Validator\Constraints\ValidCui;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wizard step 2 — one debtor in the (potentially multi-debtor) collection.
 *
 * Public mutable properties so the form can bind. `personType`, `name`, and
 * `address` are always required (a debtor can never be referenced by id —
 * the lawyer must spell them out). ANAF/BPI metadata (`anafStatus`,
 * `anafCheckedAt`, `inInsolvency`, `insolvencyCheckedAt`) is filled in Pas
 * 3.3 by the ANAF lookup Stimulus controller + BPI manual confirmation
 * checkbox; here we only declare the slots.
 */
class Step2DebtorEntry
{
    /** @param list<string> $autoFilled */
    public function __construct(
        #[Assert\NotNull(message: 'wizard.step2.error.person_type_required')]
        public ?PersonType $personType = null,
        #[Assert\NotBlank(message: 'wizard.step2.error.name_required')]
        public ?string $name = null,
        #[ValidCui]
        public ?string $cui = null,
        #[ValidCnp]
        public ?string $personalId = null,
        public ?string $onrcNumber = null,
        #[Assert\NotBlank(message: 'wizard.step2.error.address_required')]
        public ?string $address = null,
        #[Assert\Email(message: 'validation.email.invalid')]
        public ?string $email = null,
        public ?string $phone = null,
        // IBAN accepted with optional spaces / lowercase — normalized to
        // upper + stripped by Step2DebtorEntryType before this regex fires.
        #[Assert\Regex(
            pattern: '/^RO\d{2}[A-Z]{4}\d{16}$/',
            message: 'validation.iban.invalid_format',
        )]
        public ?string $iban = null,
        public ?string $administrator = null,
        public ?AnafStatus $anafStatus = null,
        public ?\DateTimeImmutable $anafCheckedAt = null,
        public bool $inInsolvency = false,
        public ?\DateTimeImmutable $insolvencyCheckedAt = null,
        public array $autoFilled = [],
    ) {}
}
