<?php

declare(strict_types=1);

namespace App\Service\Court;

use App\Entity\CourtEmailAddress;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtFilingAddressMissingReason;

/** The address a case would be filed at, or why there is none. */
final readonly class CourtFilingAddressResult
{
    private function __construct(
        public ?CourtEmailAddress $address,
        public ?CourtFilingAddressMissingReason $reason,
        public ?CourtEmailPurpose $requiredPurpose,
    ) {}

    public static function found(CourtEmailAddress $address): self
    {
        return new self($address, null, $address->getPurpose());
    }

    public static function missing(CourtFilingAddressMissingReason $reason, ?CourtEmailPurpose $requiredPurpose = null): self
    {
        return new self(null, $reason, $requiredPurpose);
    }

    public function isFound(): bool
    {
        return $this->address !== null;
    }
}
