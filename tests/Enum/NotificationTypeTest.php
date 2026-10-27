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
        self::assertSame('filing_receipt_received', NotificationType::FILING_RECEIPT_RECEIVED->value);
        self::assertSame('filing_receipt_overdue', NotificationType::FILING_RECEIPT_OVERDUE->value);
        self::assertSame('filing_bounced_permanent', NotificationType::FILING_BOUNCED_PERMANENT->value);
        self::assertSame('filing_court_message', NotificationType::FILING_COURT_MESSAGE->value);
        self::assertSame('filing_cc_bounced', NotificationType::FILING_CC_BOUNCED->value);
        self::assertSame('filing_delivery_delayed', NotificationType::FILING_DELIVERY_DELAYED->value);
        self::assertSame('filing_inbound_review', NotificationType::FILING_INBOUND_REVIEW->value);
        self::assertSame('inbound_poll_stale', NotificationType::INBOUND_POLL_STALE->value);
        self::assertSame('inbound_unprocessed', NotificationType::INBOUND_UNPROCESSED->value);
        self::assertSame('court_email_reported', NotificationType::COURT_EMAIL_REPORTED->value);
        self::assertSame('court_email_suggested', NotificationType::COURT_EMAIL_SUGGESTED->value);
        self::assertSame('court_email_report_resolved', NotificationType::COURT_EMAIL_REPORT_RESOLVED->value);
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
            NotificationType::FILING_RECEIPT_RECEIVED,
            NotificationType::FILING_RECEIPT_OVERDUE,
            NotificationType::FILING_BOUNCED_PERMANENT,
            NotificationType::FILING_COURT_MESSAGE,
            NotificationType::FILING_CC_BOUNCED,
            NotificationType::FILING_DELIVERY_DELAYED,
            NotificationType::FILING_INBOUND_REVIEW,
            NotificationType::INBOUND_POLL_STALE,
            NotificationType::INBOUND_UNPROCESSED,
            NotificationType::COURT_EMAIL_REPORTED,
            NotificationType::COURT_EMAIL_SUGGESTED,
            NotificationType::COURT_EMAIL_REPORT_RESOLVED,
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
