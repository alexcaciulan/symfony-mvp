<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a court address is for. Only the first three can ever receive a filing;
 * PERSONAL marks a nominal mailbox (a clerk's or a judge's) that is personal data.
 */
enum CourtEmailPurpose: string
{
    case REGISTRY = 'REGISTRY';
    case SECTION_CIVIL = 'SECTION_CIVIL';
    case SECTION_PROFESSIONALS = 'SECTION_PROFESSIONALS';
    case OTHER = 'OTHER';
    case PERSONAL = 'PERSONAL';

    public function label(): string
    {
        return 'enum.court_email_purpose.' . $this->value;
    }

    public function canReceiveFilings(): bool
    {
        return match ($this) {
            self::REGISTRY, self::SECTION_CIVIL, self::SECTION_PROFESSIONALS => true,
            self::OTHER, self::PERSONAL => false,
        };
    }

    /** @return list<self> the purposes a lawyer may pick when adding an address by hand */
    public static function manualChoices(): array
    {
        return [self::REGISTRY, self::SECTION_CIVIL, self::SECTION_PROFESSIONALS, self::OTHER];
    }
}
