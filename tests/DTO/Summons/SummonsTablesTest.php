<?php

declare(strict_types=1);

namespace App\Tests\DTO\Summons;

use App\DTO\Summons\LegalInterestRow;
use App\DTO\Summons\SummonsTables;
use App\Enum\ProfessionalPenaltyMargin;
use PHPUnit\Framework\TestCase;

final class SummonsTablesTest extends TestCase
{
    public function testAClaimMixingOldAndNewContractsCitesBothMarginsLowestFirst(): void
    {
        $tables = new SummonsTables([], [
            $this->row(ProfessionalPenaltyMargin::OG_ART_3_PARA_2_1),
            $this->row(ProfessionalPenaltyMargin::OG_ART_3_PARA_2),
            $this->row(ProfessionalPenaltyMargin::OG_ART_3_PARA_2_1),
        ], [], false);

        self::assertSame(
            [ProfessionalPenaltyMargin::OG_ART_3_PARA_2, ProfessionalPenaltyMargin::OG_ART_3_PARA_2_1],
            $tables->legalInterestMargins(),
        );
    }

    public function testNoLegalInterestRowsMeansNoMargin(): void
    {
        self::assertSame([], (new SummonsTables([], [], [], false))->legalInterestMargins());
    }

    private function row(ProfessionalPenaltyMargin $margin): LegalInterestRow
    {
        return new LegalInterestRow(
            documentNumber: 'F-1',
            documentDate: null,
            balance: 1000.0,
            periodStart: new \DateTimeImmutable('2025-01-02'),
            periodEnd: new \DateTimeImmutable('2025-02-01'),
            days: 31,
            nbrRate: 6.5,
            applicableRate: 6.5 + $margin->points(),
            interest: 10.0,
            margin: $margin,
        );
    }
}
