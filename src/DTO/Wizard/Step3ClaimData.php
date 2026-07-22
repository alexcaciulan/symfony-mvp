<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
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
 * the OP procedure (CPC art. 1013: "creanța să fie certă, lichidă și
 * exigibilă"). The exact "today" cutoff uses Symfony's `LessThanOrEqual`
 * with `'today'` reference, which is UTC midnight; tests use explicit
 * relative dates (-1 day / +1 day) to stay deterministic across timezones.
 */
class Step3ClaimData
{
    /**
     * Currencies a claim may be filed in. The AI extraction schema offers the
     * same list, so the model cannot prefill a value this DTO would reject.
     *
     * @var list<string>
     */
    public const SUPPORTED_CURRENCIES = ['RON', 'EUR'];

    /** @param list<string> $autoFilled */
    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'wizard.step3.error.amount_required'),
            new Assert\Positive(message: 'wizard.step3.error.amount_positive'),
        ])]
        public ?float $amount = null,
        #[Assert\NotBlank(message: 'wizard.step3.error.currency_required')]
        #[Assert\Choice(
            choices: self::SUPPORTED_CURRENCIES,
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
        #[Assert\NotNull(message: 'wizard.step3.error.penalty_type_required')]
        public ?PenaltyType $penaltyType = PenaltyType::LEGAL_PENALIZATOARE,
        #[Assert\When(
            expression: 'this.penaltyType === enum("App\\\\Enum\\\\PenaltyType::CONTRACTUAL")',
            constraints: [
                new Assert\NotNull(message: 'wizard.step3.error.penalty_rate_required'),
                new Assert\Positive(message: 'wizard.step3.error.penalty_rate_positive'),
                new Assert\LessThanOrEqual(
                    value: 5,
                    message: 'wizard.step3.error.penalty_rate_too_high',
                ),
            ],
        )]
        public ?float $contractualPenaltyRate = null,
        public ?string $contractReference = null,
        public ?string $invoiceNumber = null,
        // A foreign-currency claim is converted to RON at the BNR rate of the
        // invoice emission date, so that date is mandatory when currency != RON.
        // A future issue date is always wrong (no BNR rate exists for it yet).
        #[Assert\LessThanOrEqual(
            value: 'today',
            message: 'wizard.step3.error.invoice_date_not_future',
        )]
        #[Assert\When(
            expression: 'this.currency !== "RON"',
            constraints: [
                new Assert\NotNull(message: 'wizard.step3.error.invoice_date_required_fx'),
            ],
        )]
        public ?\DateTimeImmutable $invoiceDate = null,
        public ?string $contractNumber = null,
        public ?\DateTimeImmutable $contractDate = null,
        #[Assert\PositiveOrZero(message: 'wizard.step3.error.legal_costs_fixed_positive')]
        public ?float $legalCostsFixed = null,
        #[Assert\Choice(
            choices: self::SUPPORTED_CURRENCIES,
            message: 'wizard.step3.error.invalid_currency',
        )]
        public ?string $legalCostsCurrency = 'EUR',
        #[Assert\Range(
            min: 0,
            max: 100,
            notInRangeMessage: 'wizard.step3.error.legal_costs_percent_range',
        )]
        public ?float $legalCostsSuccessPercent = null,
        public array $autoFilled = [],
    ) {}
}
