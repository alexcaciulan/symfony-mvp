<?php

declare(strict_types=1);

namespace App\Service\Filing;

/** How the platform reacts to a failed SMTP hand-off of a court email. */
enum SmtpFailureKind: string
{
    /** Retried on the transport's schedule. */
    case TEMPORARY = 'temporary';
    /** Refused for good: no retry. */
    case PERMANENT = 'permanent';
    /** Refused for its size: no retry, the annexes may be split again. */
    case SIZE = 'size';
    /** The connection ended after the whole message was written: it may have been taken, so it is never resent. */
    case UNCERTAIN = 'uncertain';
}
