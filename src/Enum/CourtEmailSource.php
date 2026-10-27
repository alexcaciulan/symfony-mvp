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
    /** Suggested by a lawyer from a case; only an admin may confirm it. */
    case LAWYER_SUGGESTION = 'LAWYER_SUGGESTION';

    public function label(): string
    {
        return 'enum.court_email_source.' . $this->value;
    }

    public function isUnofficial(): bool
    {
        return $this !== self::MANUAL;
    }

    /** Typed in by hand (admin or lawyer): no external source can see it again. */
    public function isTypedIn(): bool
    {
        return $this === self::MANUAL || $this === self::LAWYER_SUGGESTION;
    }
}
