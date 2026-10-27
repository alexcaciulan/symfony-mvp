<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\FilingDeliveryStatus;
use PHPUnit\Framework\TestCase;

final class FilingDeliveryStatusTest extends TestCase
{
    public function testClosedPartialIsTerminalAndFreesTheCase(): void
    {
        self::assertTrue(FilingDeliveryStatus::CLOSED_PARTIAL->isTerminal());
        self::assertFalse(FilingDeliveryStatus::CLOSED_PARTIAL->holdsLock());
        self::assertFalse(FilingDeliveryStatus::CLOSED_PARTIAL->isInFlight());
    }

    public function testReviewKeepsTheCaseHeld(): void
    {
        self::assertFalse(FilingDeliveryStatus::NEEDS_REVIEW->isTerminal());
        self::assertTrue(FilingDeliveryStatus::NEEDS_REVIEW->holdsLock());
    }

    public function testEveryStatusHoldsTheCaseExactlyWhenNotTerminal(): void
    {
        foreach (FilingDeliveryStatus::cases() as $status) {
            self::assertSame(!$status->isTerminal(), $status->holdsLock(), $status->value);
        }
    }
}
