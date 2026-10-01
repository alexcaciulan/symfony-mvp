<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

/**
 * One value shown in the step 0 "Date detectate" card, already formatted for
 * display. `format` tells the template how to print it: `text` as is, `trans`
 * as a translation key, `iban` through the IBAN mask.
 */
final readonly class DetectedDataRow
{
    public function __construct(
        public string $field,
        public string $value,
        public string $format = 'text',
        public bool $inConflict = false,
    ) {}
}
