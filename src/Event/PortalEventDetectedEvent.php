<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;

/**
 * Fired by CaseMonitoringService after a new CourtPortalEvent is persisted, so
 * downstream listeners can notify the lawyer by email and real-time toast. The
 * in-app Notification is already created by CaseMonitoringService itself, so the
 * notification listener does not persist a second one for this event.
 */
final readonly class PortalEventDetectedEvent
{
    public function __construct(
        public LegalCase $case,
        public CourtPortalEvent $event,
    ) {}
}
