<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\LegalCase;
use App\Enum\DocumentType;
use App\Service\Case\CaseNextStep;
use App\Service\Case\CaseNextStepFacts;
use App\Service\Case\CaseNextStepResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Exposes the next-step rule to the case header, with the facts its context holds. */
final class CaseNextStepExtension extends AbstractExtension
{
    public function __construct(private readonly CaseNextStepResolver $resolver) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('case_next_step', $this->nextStep(...))];
    }

    public function nextStep(
        LegalCase $case,
        bool $filingPanelReady,
        bool $hasEmailFiling,
        bool $lateBounceReview,
        bool $portalRulingProposal,
    ): CaseNextStep {
        $hasProof = false;
        foreach ($case->getDocuments() as $document) {
            if ($document->getDocumentType() === DocumentType::DOVADA_COMUNICARE) {
                $hasProof = true;
                break;
            }
        }

        return $this->resolver->resolve($case, new CaseNextStepFacts(
            hasCommunicationProof: $hasProof,
            filingPanelReady: $filingPanelReady,
            hasEmailFiling: $hasEmailFiling,
            lateBounceReview: $lateBounceReview,
            portalRulingProposal: $portalRulingProposal,
        ));
    }
}
