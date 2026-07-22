<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How much a detected disagreement between documents is allowed to cost.
 *
 * The distinction is procedural, not cosmetic: an ERROR is something that would
 * put a wrong party, a wrong sum or a wrong court in a filing, so the wizard
 * must not move past it on its own.
 */
enum ConflictSeverity: string
{
    /** Blocks the wizard until the lawyer chooses. */
    case ERROR = 'ERROR';

    /** Shown and recorded; the lawyer may proceed. */
    case WARNING = 'WARNING';

    /** Stated so nothing is silent; carries no decision. */
    case INFO = 'INFO';

    public function blocks(): bool
    {
        return $this === self::ERROR;
    }
}
