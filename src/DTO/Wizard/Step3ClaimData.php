<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\LegalGroundCategory;
use App\Enum\RelationshipType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wizard step 3 — Creanță.
 *
 * Public mutable properties so the form can bind. `relationshipType`
 * defaults to COMERCIAL because the MVP is B2B-only — `RelationshipType::CIVIL`
 * throws DomainException in the calculator (Pas 2.1 Opțiunea a). The Step3
 * form hides CIVIL from the dropdown until post-MVP B2C support.
 *
 * `dueDate` must be in the past or today — a not-yet-due claim cannot enter
 * the OP procedure (CPC art. 1014: "creanța să fie certă, lichidă și
 * exigibilă"). The exact "today" cutoff uses Symfony's `LessThanOrEqual`
 * with `'today'` reference, which is UTC midnight; tests use explicit
 * relative dates (-1 day / +1 day) to stay deterministic across timezones.
 */
class Step3ClaimData
{
    /** @param list<string> $autoFilled */
    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'wizard.step3.error.amount_required'),
            new Assert\Positive(message: 'wizard.step3.error.amount_positive'),
        ])]
        public ?float $amount = null,
        #[Assert\NotBlank(message: 'wizard.step3.error.currency_required')]
        #[Assert\Choice(
            choices: ['RON', 'EUR'],
            message: 'wizard.step3.error.invalid_currency',
        )]
        public string $currency = 'RON',
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'wizard.step3.error.due_date_required'),
            new Assert\LessThanOrEqual(
                value: 'today',
                message: 'wizard.step3.error.due_date_not_future',
            ),
        ])]
        public ?\DateTimeImmutable $dueDate = null,
        #[Assert\NotNull(message: 'wizard.step3.error.relationship_required')]
        public ?RelationshipType $relationshipType = RelationshipType::COMERCIAL,
        public ?LegalGroundCategory $legalGround = null,
        public ?string $description = null,
        public array $autoFilled = [],
    ) {}
}
