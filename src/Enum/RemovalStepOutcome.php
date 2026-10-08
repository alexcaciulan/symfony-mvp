<?php

declare(strict_types=1);

namespace App\Enum;

/** What a saved wizard step becomes when a document is removed. */
enum RemovalStepOutcome: string
{
    /** The step was never saved: it is filled from the documents left anyway. */
    case NOT_SAVED = 'not_saved';
    /** Same party (or typed by the lawyer): kept as the lawyer left it. */
    case KEPT = 'kept';
    /** Another party, or none, in the documents left: filled again from them. */
    case REFILLED = 'refilled';
}
