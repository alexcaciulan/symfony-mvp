<?php

declare(strict_types=1);

namespace App\Service\Address;

/**
 * Street-level components of a Romanian address, already split apart.
 *
 * Deliberately excludes locality and county: those live in their own entity
 * fields (`addressLocality` / `addressCounty`) because they drive the competent
 * court and the stamp-duty town hall. Repeating them inside the address string
 * is what produced "Bucureşti" three times over in the same line.
 *
 * @see RomanianAddressFormatter for the single place that turns this into text
 */
final readonly class AddressParts
{
    /** @param list<string> $details free-form fragments (building name, office number) */
    public function __construct(
        public ?string $street = null,
        public ?string $streetNumber = null,
        public ?string $block = null,
        public ?string $staircase = null,
        public ?string $floor = null,
        public ?string $apartment = null,
        public array $details = [],
        public ?string $postalCode = null,
    ) {}

    public function hasUnitDetails(): bool
    {
        return $this->block !== null
            || $this->staircase !== null
            || $this->floor !== null
            || $this->apartment !== null;
    }
}
