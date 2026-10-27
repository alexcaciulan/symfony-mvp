<?php

declare(strict_types=1);

namespace App\Service\Filing;

/** The unsigned files of a package cannot be put together into one archive. */
final class UnsignedArchiveUnavailableException extends \RuntimeException
{
}
