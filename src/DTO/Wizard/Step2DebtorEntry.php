<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\AnafStatus;
use App\Enum\PersonType;

/**
 * Wizard step 2 — one debtor in the (potentially multi-debtor) collection.
 *
 * Skeletal version (Pas 3.0): public mutable properties so the form can bind.
 * Pas 3.1 adds Symfony Validator constraints (CUI checksum, NotBlank for the
 * required subset). ANAF/BPI metadata (anafStatus, anafCheckedAt, inInsolvency,
 * insolvencyCheckedAt) is filled in Pas 3.3 by the ANAF lookup Stimulus
 * controller + BPI manual confirmation checkbox.
 */
class Step2DebtorEntry
{
    /** @param list<string> $autoFilled */
    public function __construct(
        public ?PersonType $personType = null,
        public ?string $name = null,
        public ?string $cui = null,
        public ?string $personalId = null,
        public ?string $onrcNumber = null,
        public ?string $address = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $iban = null,
        public ?string $administrator = null,
        public ?AnafStatus $anafStatus = null,
        public ?\DateTimeImmutable $anafCheckedAt = null,
        public bool $inInsolvency = false,
        public ?\DateTimeImmutable $insolvencyCheckedAt = null,
        public array $autoFilled = [],
    ) {}
}
