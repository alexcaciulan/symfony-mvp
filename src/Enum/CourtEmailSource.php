<?php

declare(strict_types=1);

namespace App\Enum;

/** Where an address came from. None of these sources is an official court registry. */
enum CourtEmailSource: string
{
    case MANUAL = 'MANUAL';
    case PORTAL_IMPORT = 'PORTAL_IMPORT';
    /** A portal page saved from a browser by an operator and imported offline. */
    case SAVED_PAGE = 'SAVED_PAGE';

    public function label(): string
    {
        return 'enum.court_email_source.' . $this->value;
    }

    public function isUnofficial(): bool
    {
        return $this !== self::MANUAL;
    }
}
