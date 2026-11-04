<?php

declare(strict_types=1);

namespace App\DTO\Case;

/** What a save of the recovery costs changed. */
final readonly class LegalCostsChange
{
    /**
     * @param array<string, array{from: ?string, to: ?string}> $changed                    the fields that moved, by name
     * @param bool                                             $paymentOrderMarkedOutdated a generated petition was marked outdated by this save
     */
    public function __construct(
        public array $changed = [],
        public bool $paymentOrderMarkedOutdated = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->changed === [];
    }
}
