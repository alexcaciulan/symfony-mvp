<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Enum\DeadlineBlockageReason;

/**
 * One rendered row of the blockage zone: the blocked case with the button already
 * decided for it, the same split the agenda rows use. The template reads properties,
 * it decides nothing.
 */
final readonly class DeadlineBlockageView
{
    public function __construct(
        public DeadlineBlockage $blockage,
        public DeadlineActionButton $action,
    ) {}

    public function legalCase(): LegalCase
    {
        return $this->blockage->legalCase;
    }

    public function reason(): DeadlineBlockageReason
    {
        return $this->blockage->reason;
    }
}
