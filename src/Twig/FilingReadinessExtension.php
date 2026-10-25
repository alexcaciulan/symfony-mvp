<?php

declare(strict_types=1);

namespace App\Twig;

use App\DTO\Case\FilingReadiness;
use App\Entity\LegalCase;
use App\Service\Case\FilingReadinessChecker;
use App\Service\Document\PowerOfAttorneyService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the filing check to the templates, so every stream that re-renders the
 * generation dialog or the package card shows the same blockers the controller
 * enforces, without each caller passing them in.
 */
final class FilingReadinessExtension extends AbstractExtension
{
    public function __construct(
        private readonly FilingReadinessChecker $checker,
        private readonly PowerOfAttorneyService $powerOfAttorneyService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('filing_readiness', fn (LegalCase $case): FilingReadiness => $this->checker->check($case)),
            new TwigFunction('power_of_attorney_editable', fn (LegalCase $case): bool => $this->powerOfAttorneyService->canEditDetails($case)),
        ];
    }
}
