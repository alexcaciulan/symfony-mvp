<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\LegalCase;
use App\Service\Calculation\StampDutyCalculator;
use App\Service\Case\CaseStampDuty;
use PHPUnit\Framework\TestCase;

final class CaseStampDutyTest extends TestCase
{
    public function testWritesTheFixedDutyAndReportsTheMove(): void
    {
        $case = new LegalCase();
        $service = new CaseStampDuty(new StampDutyCalculator(200.0, 'OUG 80/2013'));

        self::assertSame(['from' => null, 'to' => '200.00'], $service->refresh($case));
        self::assertSame('200.00', $case->getStampDuty());
    }

    public function testStaysSilentWhenTheStoredDutyAlreadyMatches(): void
    {
        $case = new LegalCase();
        $case->setStampDuty('200.00');
        $service = new CaseStampDuty(new StampDutyCalculator(200.0, 'OUG 80/2013'));

        self::assertNull($service->refresh($case));
        self::assertSame('200.00', $case->getStampDuty());
    }

    public function testCorrectsAStoredDutyThatNoLongerMatchesTheRule(): void
    {
        $case = new LegalCase();
        $case->setStampDuty('150.00');
        $service = new CaseStampDuty(new StampDutyCalculator(200.0, 'OUG 80/2013'));

        self::assertSame(['from' => '150.00', 'to' => '200.00'], $service->refresh($case));
    }
}
