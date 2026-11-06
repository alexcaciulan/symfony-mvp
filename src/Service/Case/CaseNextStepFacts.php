<?php

declare(strict_types=1);

namespace App\Service\Case;

/**
 * Facts the next step depends on that are not fields of the case. The case page already
 * holds them in its context; the deadlines page loads them in bulk for its rows.
 */
final readonly class CaseNextStepFacts
{
    public function __construct(
        public bool $hasCommunicationProof,
        public bool $filingPanelReady = false,
        public bool $hasEmailFiling = false,
        public bool $lateBounceReview = false,
        public bool $portalRulingProposal = false,
    ) {}
}
