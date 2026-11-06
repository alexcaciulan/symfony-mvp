<?php

declare(strict_types=1);

namespace App\Service\Case;

/**
 * What the case header states as the next act: the band that has to be dealt with
 * first, if any, whether the Depunere tab leads, and the last segment of the sentence
 * key under `case_overview.hero.next_step`.
 */
final readonly class CaseNextStep
{
    public function __construct(
        public ?string $blockedBy,
        public bool $filingLink,
        public string $sentenceKey,
    ) {}
}
