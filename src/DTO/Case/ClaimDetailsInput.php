<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\Entity\LegalCase;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\PenaltyType;
use App\Validator\Constraints\AccessoryCutoffRules;
use App\Validator\Constraints\ContractualPenaltyCapRules;
use App\Validator\Constraints\ContractualPenaltyRateRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The claim fields the lawyer may rewrite from the case page without touching
 * the positions: the object of the claim, the contract and clause references
 * the summons quotes, the notice number from the firm's outgoing register, and
 * the accessory configuration (mode, contractual rate and cap, the contract's
 * name for the accessory, the cutoff date, the contract's own data).
 *
 * The accessory rules are the wizard's, through the shared constraints.
 * `dueDate` is not a form field: it carries the case's earliest due date so the
 * cutoff rule reads the same as at creation. Start from {@see fromCase()}: an
 * input built from scratch defaults to the statutory mode and would switch a
 * contractual case over.
 */
class ClaimDetailsInput
{
    public function __construct(
        #[Assert\Length(max: 2000, maxMessage: 'case.claim.edit.error.description_too_long')]
        public ?string $claimDescription = null,
        #[Assert\Length(max: 255, maxMessage: 'case.claim.edit.error.contract_reference_too_long')]
        public ?string $contractReference = null,
        #[Assert\Length(max: 100, maxMessage: 'case.claim.edit.error.penalty_clause_article_too_long')]
        public ?string $penaltyClauseArticle = null,
        #[Assert\Length(max: 10000, maxMessage: 'case.claim.edit.error.penalty_clause_text_too_long')]
        public ?string $penaltyClauseText = null,
        #[Assert\Length(max: 50, maxMessage: 'case.claim.edit.error.payment_notice_number_too_long')]
        public ?string $paymentNoticeNumber = null,
        #[Assert\NotNull(message: 'wizard.step3.error.penalty_type_required')]
        public ?PenaltyType $penaltyType = PenaltyType::LEGAL_PENALIZATOARE,
        #[ContractualPenaltyRateRules]
        public ?float $contractualPenaltyRate = null,
        #[ContractualPenaltyCapRules]
        public ?float $contractualPenaltyCapPercent = null,
        public ?ContractualAccessoryLabel $contractualAccessoryLabel = null,
        #[AccessoryCutoffRules]
        public ?\DateTimeImmutable $accessoryCutoffDate = null,
        #[Assert\Length(max: 100, maxMessage: 'wizard.step3.error.contract_number_too_long')]
        public ?string $contractNumber = null,
        #[Assert\LessThanOrEqual(value: 'today', message: 'wizard.step3.error.contract_date_not_future')]
        public ?\DateTimeImmutable $contractDate = null,
        #[Assert\Length(max: 255, maxMessage: 'wizard.step3.error.contract_object_too_long')]
        public ?string $contractObject = null,
        public ?\DateTimeImmutable $dueDate = null,
    ) {}

    /** The case's details as they stand, for the dialog to start from. */
    public static function fromCase(LegalCase $case): self
    {
        $rate = $case->getContractualPenaltyRate();
        $dueDate = $case->getDueDate();

        return new self(
            claimDescription: $case->getClaimDescription(),
            contractReference: $case->getContractReference(),
            penaltyClauseArticle: $case->getPenaltyClauseArticle(),
            penaltyClauseText: $case->getPenaltyClauseText(),
            paymentNoticeNumber: $case->getPaymentNoticeNumber(),
            penaltyType: $case->getPenaltyType() ?? PenaltyType::LEGAL_PENALIZATOARE,
            contractualPenaltyRate: $rate !== null ? (float) $rate : null,
            contractualPenaltyCapPercent: $case->contractualPenaltyCap(),
            contractualAccessoryLabel: $case->getContractualAccessoryLabel(),
            accessoryCutoffDate: $case->getAccessoryCutoffDate(),
            contractNumber: $case->getContractNumber(),
            contractDate: $case->contractDateImmutable(),
            contractObject: $case->getContractObject(),
            dueDate: $dueDate !== null ? \DateTimeImmutable::createFromInterface($dueDate) : null,
        );
    }
}
