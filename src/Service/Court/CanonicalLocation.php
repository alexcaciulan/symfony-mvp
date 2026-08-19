<?php

declare(strict_types=1);

namespace App\Service\Court;

/**
 * A county and a locality expressed in SIRUTA spelling, plus whether each one
 * actually matched a nomenclature row. An unmatched value is the raw input,
 * kept so the lawyer can correct it instead of finding the field emptied.
 */
final readonly class CanonicalLocation
{
    public function __construct(
        public ?string $countyName,
        public ?string $localityName,
        public bool $countyMatched,
        public bool $localityMatched,
    ) {}
}
