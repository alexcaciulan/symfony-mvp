<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Enum\InboundMatchConfidence;
use App\Enum\InboundMatchMethod;

final readonly class InboundMatch
{
    public function __construct(
        public ?FilingEmailPart $part,
        public FilingSubmission $submission,
        public InboundMatchMethod $method,
        public InboundMatchConfidence $confidence,
    ) {}

    public function isCertain(): bool
    {
        return $this->confidence === InboundMatchConfidence::CERTAIN;
    }
}
