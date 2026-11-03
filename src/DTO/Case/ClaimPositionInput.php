<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\DTO\Wizard\Step3ClaimData;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One invoice the lawyer adds to the claim from the case page, typed by hand
 * or read from an uploaded invoice and corrected before saving.
 *
 * The due date is mandatory and may lie in the future: a position not yet
 * due is legitimate before the summons and only blocks the summons itself.
 * Public mutable properties so the form binds to it.
 */
class ClaimPositionInput
{
    public function __construct(
        #[Assert\Length(max: 100, maxMessage: 'case.claim.edit.error.document_number_too_long')]
        public ?string $documentNumber = null,
        #[Assert\LessThanOrEqual(value: 'today', message: 'case.claim.edit.error.document_date_future')]
        #[Assert\When(
            expression: 'this.currency !== "RON"',
            constraints: [new Assert\NotNull(message: 'case.claim.edit.error.document_date_required_fx')],
        )]
        public ?\DateTimeImmutable $documentDate = null,
        #[Assert\NotNull(message: 'case.claim.edit.error.due_date_required')]
        public ?\DateTimeImmutable $dueDate = null,
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'case.claim.edit.error.amount_required'),
            new Assert\Positive(message: 'case.claim.edit.error.amount_positive'),
        ])]
        public ?float $amount = null,
        #[Assert\NotBlank(message: 'case.claim.edit.error.currency_required')]
        #[Assert\Choice(choices: Step3ClaimData::SUPPORTED_CURRENCIES, message: 'case.claim.edit.error.invalid_currency')]
        public string $currency = 'RON',
        // The debtor link the invoice was issued to; required by the editor when
        // the case names more than one debtor.
        public ?int $debtorId = null,
        #[Assert\Length(max: 255, maxMessage: 'case.claim.edit.error.cause_reference_too_long')]
        public ?string $causeReference = null,
        #[Assert\Length(max: 2000, maxMessage: 'case.claim.edit.error.description_too_long')]
        public ?string $description = null,
        public ?int $sourceDocumentId = null,
        // Ticked by the lawyer when the invoice names another contract than the
        // case and he still wants it in this claim (CPC art. 99).
        public bool $sameCauseConfirmed = false,
        // Ticked when the case's contractual penalty clause applies to that invoice too.
        public bool $clauseAppliesConfirmed = false,
    ) {}
}
