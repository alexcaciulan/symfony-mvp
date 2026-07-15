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
