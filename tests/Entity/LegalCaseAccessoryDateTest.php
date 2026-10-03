<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LegalCase;
use PHPUnit\Framework\TestCase;

final class LegalCaseAccessoryDateTest extends TestCase
{
    public function testWithoutACutoffEachDocumentKeepsItsOwnDate(): void
    {
        $default = new \DateTimeImmutable('2025-03-02');

        self::assertSame($default, (new LegalCase())->accessoryReferenceDate($default));
    }

    public function testAnEarlierCutoffStopsTheAccessories(): void
    {
        $case = (new LegalCase())->setAccessoryCutoffDate(new \DateTimeImmutable('2025-01-31'));

        self::assertSame('2025-01-31', $case->accessoryReferenceDate(new \DateTimeImmutable('2025-03-02'))->format('Y-m-d'));
    }

    public function testALaterCutoffNeverExtendsADocumentsDate(): void
    {
        $case = (new LegalCase())->setAccessoryCutoffDate(new \DateTimeImmutable('2025-06-01'));

        self::assertSame('2025-03-02', $case->accessoryReferenceDate(new \DateTimeImmutable('2025-03-02'))->format('Y-m-d'));
    }
}
