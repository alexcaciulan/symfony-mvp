<?php

declare(strict_types=1);

namespace App\DTO\Case;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The claim fields the lawyer may rewrite from the case page without touching
 * the sums: the object of the claim, the contract and clause references the
 * summons quotes, and the notice number from the firm's outgoing register.
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
    ) {}
}
