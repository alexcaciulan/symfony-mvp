<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\PersonType;

/**
 * Wizard step 1 — Creditor.
 *
 * Skeletal version (Pas 3.0): public mutable properties so the form can bind
 * directly via `data_class`. Pas 3.1 adds Symfony Validator constraints +
 * conditional validation (creditorId xor manual fields).
 *
 * `autoFilled` carries the list of field names that the prefill aggregator
 * populated from extracted documents — the form renders a badge "auto · N%"
 * for each of those.
 */
class Step1CreditorData
{
    /** @param list<string> $autoFilled */
    public function __construct(
        public ?int $creditorId = null,
        public ?PersonType $personType = null,
        public ?string $name = null,
        public ?string $cui = null,
        public ?string $personalId = null,
        public ?string $onrcNumber = null,
        public ?string $address = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $iban = null,
        public ?string $legalRepresentative = null,
        public array $autoFilled = [],
    ) {}
}
