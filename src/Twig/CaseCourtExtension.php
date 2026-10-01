<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Service\Case\DebtorSeatCourtCheck;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CaseCourtExtension extends AbstractExtension
{
    public function __construct(
        private readonly DebtorSeatCourtCheck $seatCheck,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('debtor_seat_court', fn (LegalCase $case): ?Court => $this->seatCheck->courtNowPointedTo($case)),
            new TwigFunction('debtor_changes_since_summons', fn (LegalCase $case): array => $this->seatCheck->changesSinceSummons($case)),
            new TwigFunction('creditor_changes_since_summons', fn (LegalCase $case): array => $this->seatCheck->creditorChangesSinceSummons($case)),
        ];
    }
}
