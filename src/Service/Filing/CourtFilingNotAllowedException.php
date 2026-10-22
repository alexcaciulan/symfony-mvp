<?php

declare(strict_types=1);

namespace App\Service\Filing;

/** The configuration would let a filing reach a court, or a third party, it must not reach. */
final class CourtFilingNotAllowedException extends \RuntimeException
{
}
