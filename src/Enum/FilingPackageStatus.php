<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of a filing package: the unsigned files the platform built, the
 * lawyer's signatures over them, and the moment they left for the court.
 */
enum FilingPackageStatus: string
{
    case BUILT = 'BUILT';
    /** At least one unsigned file was downloaded, so signing may be under way. */
    case AWAITING_SIGNATURES = 'AWAITING_SIGNATURES';
    case SIGNED = 'SIGNED';
    /** Its sources changed after the build: the files no longer match the case. */
    case STALE = 'STALE';
    /** Replaced by a newer version; its files are kept as the record of what existed. */
    case SUPERSEDED = 'SUPERSEDED';
    /** What left for the court is a fact, so nothing on the case may make it stale. */
    case SENT = 'SENT';

    public function label(): string
    {
        return 'enum.filing_package_status.' . $this->value;
    }

    public function canBecomeStale(): bool
    {
        return match ($this) {
            self::BUILT, self::AWAITING_SIGNATURES, self::SIGNED => true,
            self::STALE, self::SUPERSEDED, self::SENT => false,
        };
    }

    /** Whether this is the package the case card shows. */
    public function isCurrent(): bool
    {
        return $this !== self::SUPERSEDED;
    }
}
