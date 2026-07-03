<?php

declare(strict_types=1);

namespace App\DTO\Portal;

use App\Entity\CourtPortalEvent;

/**
 * A portal-detected ruling surfaced to the lawyer for confirmation. The
 * transition is never applied automatically (a wrong emite_ordonanta would
 * start the critical 10-day annulment window, CPC art. 1024, on wrong data);
 * the lawyer confirms it from `modalTarget`, pre-filled with `prefillDate`.
 */
final readonly class RulingProposal
{
    public function __construct(
        public CourtPortalEvent $event,
        public string $transition,
        public string $modalTarget,
        public ?\DateTimeImmutable $prefillDate,
    ) {}
}
