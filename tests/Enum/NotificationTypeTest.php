<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\NotificationType;
use PHPUnit\Framework\TestCase;

final class NotificationTypeTest extends TestCase
{
    public function testBackingValuesAreStable(): void
    {
        self::assertSame('case_status', NotificationType::CASE_STATUS->value);
        self::assertSame('deadline_alert', NotificationType::DEADLINE_ALERT->value);
        self::assertSame('missing_communication_date', NotificationType::MISSING_COMMUNICATION_DATE->value);
        self::assertSame('portal_event', NotificationType::PORTAL_EVENT->value);
        self::assertSame('filing_sent', NotificationType::FILING_SENT->value);
        self::assertSame('filing_annexes_cancelled', NotificationType::FILING_ANNEXES_CANCELLED->value);
        self::assertSame('filing_failed_temporary', NotificationType::FILING_FAILED_TEMPORARY->value);
        self::assertSame('filing_failed_permanent', NotificationType::FILING_FAILED_PERMANENT->value);
        self::assertSame('filing_failed_size', NotificationType::FILING_FAILED_SIZE->value);
        self::assertSame('filing_failed_internal', NotificationType::FILING_FAILED_INTERNAL->value);
        self::assertSame('filing_needs_review', NotificationType::FILING_NEEDS_REVIEW->value);
        self::assertSame('filing_partially_sent', NotificationType::FILING_PARTIALLY_SENT->value);
    }

    public function testFilingFailureTypesAreHighTierAndNeverMuted(): void
    {
        $types = [
            NotificationType::FILING_FAILED_TEMPORARY,
            NotificationType::FILING_FAILED_PERMANENT,
            NotificationType::FILING_FAILED_SIZE,
            NotificationType::FILING_FAILED_INTERNAL,
            NotificationType::FILING_NEEDS_REVIEW,
            NotificationType::FILING_PARTIALLY_SENT,
        ];
        foreach ($types as $type) {
            self::assertFalse($type->suppressible(), $type->value);
            self::assertSame('high', $type->tier(), $type->value);
            self::assertSame('document', $type->icon(), $type->value);
        }
    }

    public function testLabelKeyFollowsConvention(): void
    {
        foreach (NotificationType::cases() as $type) {
            self::assertSame('enum.notification_type.' . $type->value, $type->label());
        }
    }

    public function testEveryCaseHasNonEmptyIconAndTier(): void
    {
        foreach (NotificationType::cases() as $type) {
            self::assertNotEmpty($type->icon());
            self::assertContains($type->tier(), ['high', 'medium', 'low']);
        }
    }

    public function testCurrentTypesAreNonSuppressible(): void
    {
        // The current set (legal status, deadlines, portal, missing service date)
        // is all legally load-bearing and must never be muted by preferences.
        foreach (NotificationType::cases() as $type) {
            self::assertFalse($type->suppressible());
        }
    }
}
