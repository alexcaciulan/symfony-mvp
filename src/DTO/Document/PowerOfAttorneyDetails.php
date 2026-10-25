<?php

declare(strict_types=1);

namespace App\DTO\Document;

/**
 * Series, number and date of a power of attorney as declared by the lawyer. The
 * date is required wherever the details are given: it anchors the mandate in time
 * and the index prints it, while series and number are bar practice, not law.
 */
final readonly class PowerOfAttorneyDetails
{
    public function __construct(
        public ?string $series,
        public ?string $number,
        public \DateTimeImmutable $date,
    ) {}
}
