<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\LegalCase;

/**
 * Domain event fired by {@see \App\Service\Deadline\CaseAutoFinalizer} when a case
 * in ORDONANTA_EMISA cannot be evaluated for auto-finalization because
 * `rulingCommunicationDate` is missing. The 10-day annulment-request term (CPC
 * art. 1024 para. 1) runs from SERVICE of the order, so without this date the
 * system cannot safely mark the case final.
 *
 * Consumed by an event subscriber that prompts the lawyer to fill in the
 * communication date so the annulment-request term can start.
 */
final readonly class MissingCommunicationDateEvent
{
    public function __construct(public LegalCase $case) {}
}
