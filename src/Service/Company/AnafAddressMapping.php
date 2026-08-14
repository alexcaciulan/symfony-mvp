<?php

declare(strict_types=1);

namespace App\Service\Company;

use App\Service\Address\AddressParts;

/**
 * Outcome of reading an ANAF payload as an address.
 *
 * `$fiscalDomicileDiffers` reports that ANAF holds a different fiscal domicile
 * than the registered office. When that happens the block / staircase / floor /
 * apartment tokens are NOT imported, because their only source describes the
 * other address, and the wizard warns the lawyer to complete them by hand.
 */
final readonly class AnafAddressMapping
{
    public function __construct(
        public AddressParts $parts,
        public bool $fiscalDomicileDiffers,
    ) {}
}
