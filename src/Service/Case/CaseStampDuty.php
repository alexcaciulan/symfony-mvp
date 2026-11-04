<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\LegalCase;
use App\Service\Calculation\StampDutyCalculator;

/**
 * Owner of the stamp duty stored on a case. The payment order carries a fixed
 * duty (OUG 80/2013 art. 6 alin. 2), so the figure does not follow the claim;
 * it is still written here and nowhere else, so a change of the rule has one
 * place to land, whichever flow saved or edited the claim.
 */
final class CaseStampDuty
{
    public function __construct(
        private readonly StampDutyCalculator $calculator,
    ) {}

    /**
     * Returns the previous and new figures when the stored duty moved, null
     * when it already was what the rule says.
     *
     * @return array{from: ?string, to: string}|null
     */
    public function refresh(LegalCase $case): ?array
    {
        $from = $case->getStampDuty();
        $to = sprintf('%.2f', $this->calculator->calculate()->amount);
        if ($from !== null && abs((float) $from - (float) $to) < 0.005) {
            return null;
        }
        $case->setStampDuty($to);

        return ['from' => $from, 'to' => $to];
    }
}
