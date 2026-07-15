<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\PersonType;
use App\Validator\Constraints\ValidCnp;
use App\Validator\Constraints\ValidCui;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Wizard step 1 — Creditor.
 *
 * Public mutable properties so the form can bind directly via `data_class`.
 * `autoFilled` carries the list of field names that the prefill aggregator
 * populated from extracted documents — the form renders a badge "auto · N%"
 * for each of those.
 *
 * Validation contract:
 *  - When `creditorId` is set (user picked an existing creditor via the
 *    autocomplete), the manual fields are skipped — the form's
 *    `validation_groups` callback drops the `manual` group entirely.
 *  - Otherwise (`manual` group active), `personType`, `name` and `address`
 *    are required unconditionally, and the `validateConditionalRequiredFields`
 *    callback enforces `cui`+`onrcNumber` for PJ or `personalId` (CNP) for PF.
 *
 * Each missing field gets its own violation on its own property path so the
 * Twig templates can render the error inline under the correct input.
 */
class Step1CreditorData
{
    /** @param list<string> $autoFilled */
    public function __construct(
        public ?int $creditorId = null,
        #[Assert\NotNull(
            message: 'wizard.step1.error.person_type_required',
            groups: ['manual'],
        )]
        public ?PersonType $personType = null,
        #[Assert\NotBlank(
            message: 'wizard.step1.error.name_required',
            groups: ['manual'],
        )]
        public ?string $name = null,
        #[ValidCui]
        public ?string $cui = null,
        #[ValidCnp]
        public ?string $personalId = null,
        public ?string $onrcNumber = null,
        #[Assert\NotBlank(
            message: 'wizard.step1.error.address_required',
            groups: ['manual'],
        )]
        public ?string $address = null,
        // Structured registered office, filled by the ANAF lookup and correctable
        // by the lawyer. Drives the UAT that collects the stamp duty (OUG 80/2013
        // art. 40 alin. 1), which cannot be derived from the free-text address.
        #[Assert\Length(max: 100)]
        public ?string $addressCounty = null,
        #[Assert\Length(max: 150)]
        public ?string $addressLocality = null,
        public ?string $anafCheckedAt = null,
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
        public ?string $bankName = null,
        public ?string $legalRepresentative = null,
        public array $autoFilled = [],
    ) {}

    /**
     * Conditional NotBlank for PJ (CUI + ONRC) and PF (CNP).
     *
     * Runs only in the `manual` group — when the user picked an existing
     * creditor via autocomplete, the form drops `manual` from
     * `validation_groups` so this callback is never invoked.
     */
    #[Assert\Callback(groups: ['manual'])]
    public function validateConditionalRequiredFields(ExecutionContextInterface $context): void
    {
        if ($this->personType === PersonType::PJ) {
            if ($this->cui === null || $this->cui === '') {
                $context->buildViolation('wizard.step1.error.cui_required')
                    ->atPath('cui')
                    ->addViolation();
            }
            if ($this->onrcNumber === null || $this->onrcNumber === '') {
                $context->buildViolation('wizard.step1.error.onrc_required')
                    ->atPath('onrcNumber')
                    ->addViolation();
            }
        } elseif ($this->personType === PersonType::PF) {
            if ($this->personalId === null || $this->personalId === '') {
                $context->buildViolation('wizard.step1.error.cnp_required')
                    ->atPath('personalId')
                    ->addViolation();
            }
        }
    }
}
