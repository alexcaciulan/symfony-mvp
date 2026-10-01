<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\AnafStatus;
use App\Enum\PersonType;
use App\Validator\Constraints\ValidCnp;
use App\Validator\Constraints\ValidCui;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Wizard step 2: one debtor in the (potentially multi-debtor) collection.
 *
 * Public mutable properties so the form can bind. `personType`, `name`, and
 * `address` are always required, also for a company taken from the library
 * (`debtorId`), whose identity is shown read-only and checked the same way.
 * For PJ, `cui` and `onrcNumber` are also
 * required; for PF, `personalId` (CNP) is required — enforced by the
 * `validateConditionalRequiredFields` callback.
 *
 * `anafStatus` / `anafCheckedAt` are filled by the ANAF lookup Stimulus
 * controller; `insolvencyCheckedAt` by the mandatory BPI confirmation checkbox.
 * `inInsolvency` is not written by the wizard (a lawyer who finds the debtor in
 * the Law 85/2014 proceedings must not file at all); OpAdmissibilityValidator
 * still reads it.
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
        // Bounded to their column widths so an over-long AI-extracted value gets
        // a validation message rather than 500-ing the save on truncation.
        #[Assert\Length(max: 50)]
        public ?string $onrcNumber = null,
        #[Assert\NotBlank(message: 'wizard.step2.error.address_required')]
        public ?string $address = null,
        // County + locality drive competent-court resolution. Optional: when
        // missing the resolver returns court=null (manual pick at step 4).
        // Populated by the ANAF lookup, AI extraction, or manually.
        #[Assert\Length(max: 100)]
        public ?string $addressCounty = null,
        #[Assert\Length(max: 150)]
        public ?string $addressLocality = null,
        #[Assert\Email(message: 'validation.email.invalid')]
        public ?string $email = null,
        #[Assert\Length(max: 30)]
        public ?string $phone = null,
        // IBAN accepted with optional spaces / lowercase — normalized to
        // upper + stripped by Step2DebtorEntryType before this regex fires.
        #[Assert\Regex(
            pattern: '/^RO\d{2}[A-Z]{4}[A-Z0-9]{16}$/',
            message: 'validation.iban.invalid_format',
        )]
        public ?string $iban = null,
        public ?string $administrator = null,
        public ?AnafStatus $anafStatus = null,
        public ?\DateTimeImmutable $anafCheckedAt = null,
        public bool $inInsolvency = false,
        // Set from the `bpiVerifiedToday` checkbox by Step2DebtorEntryType. The
        // attestation is mandatory only for a PJ debtor (enforced in the callback
        // below): BPI (Legea 85/2014) is searched by CUI, which the wizard only
        // collects for PJ. The PF path relies on the manual-check WARNING that
        // OpAdmissibilityValidator emits at step 4, rather than a mandatory tick
        // here. (A PFA/II/IF debtor is a professionist that does appear in BPI,
        // but is still modelled as PersonType::PF in this MVP with no CUI field;
        // treating it as a full professional debtor is deferred, see backlog.)
        public ?\DateTimeImmutable $insolvencyCheckedAt = null,
        public array $autoFilled = [],
        ?int $debtorId = null,
        bool $updateLibrary = false,
    ) {
        $this->debtorId = $debtorId;
        $this->updateLibrary = $updateLibrary;
    }

    /**
     * The library company this entry was picked from; its identity then comes
     * from the library and the case links to that company. Declared with a
     * default (not promoted) so an entry unserialized from a session written
     * before the property existed reads null rather than failing.
     */
    public ?int $debtorId = null;

    /** Set when the lawyer chose to update the library company with this step's data. */
    public bool $updateLibrary = false;

    /**
     * Conditional NotBlank for PJ (CUI + ONRC) and PF (CNP).
     *
     * Each missing field gets its own violation on its own property path so
     * the Twig templates can render the error inline under the correct input.
     */
    #[Assert\Callback]
    public function validateConditionalRequiredFields(ExecutionContextInterface $context): void
    {
        if ($this->personType === PersonType::PJ) {
            if ($this->cui === null || $this->cui === '') {
                $context->buildViolation('wizard.step2.error.cui_required')
                    ->atPath('cui')
                    ->addViolation();
            }
            if ($this->onrcNumber === null || $this->onrcNumber === '') {
                $context->buildViolation('wizard.step2.error.onrc_required')
                    ->atPath('onrcNumber')
                    ->addViolation();
            }
            // BPI attestation, mandatory for legal entities only (see the
            // property comment). Routed to the `bpiVerifiedToday` checkbox by
            // the form's error_mapping.
            if ($this->insolvencyCheckedAt === null) {
                $context->buildViolation('wizard.step2.error.bpi_verification_required')
                    ->atPath('insolvencyCheckedAt')
                    ->addViolation();
            }
        } elseif ($this->personType === PersonType::PF) {
            if ($this->personalId === null || $this->personalId === '') {
                $context->buildViolation('wizard.step2.error.cnp_required')
                    ->atPath('personalId')
                    ->addViolation();
            }
        }
    }
}
