<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\LegalGroundCategory;
use App\Enum\RelationshipType;

/**
 * Wizard step 3 — Creanță.
 *
 * Skeletal version (Pas 3.0): public mutable properties so the form can bind.
 * Pas 3.1 adds Symfony Validator constraints (amount > 0, dueDate <= today).
 *
 * `relationshipType` defaults to COMERCIAL because the MVP is B2B-only —
 * RelationshipType::CIVIL throws DomainException in the calculator (Pas 2.1
 * Opțiunea a). The UI dropdown will hide CIVIL until post-MVP B2C support.
 */
class Step3ClaimData
{
    /** @param list<string> $autoFilled */
    public function __construct(
        public ?float $amount = null,
        public string $currency = 'RON',
        public ?\DateTimeImmutable $dueDate = null,
        public ?RelationshipType $relationshipType = RelationshipType::COMERCIAL,
        public ?LegalGroundCategory $legalGround = null,
        public ?string $description = null,
        public array $autoFilled = [],
    ) {}
}
