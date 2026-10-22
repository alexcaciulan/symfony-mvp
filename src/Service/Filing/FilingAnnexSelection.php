<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\Document;

/** The case documents a filing package is built from. */
final readonly class FilingAnnexSelection
{
    /**
     * @param list<Document> $annexes          in index order
     * @param list<Document> $skippedOptional  optional annexes whose file is gone
     * @param list<Document> $excludedPowersOfAttorney undated or older copies, not packaged
     */
    public function __construct(
        public Document $petition,
        public Document $powerOfAttorney,
        public array $annexes,
        public bool $stampDutyProofAnnexed,
        public array $skippedOptional,
        public array $excludedPowersOfAttorney,
    ) {}
}
