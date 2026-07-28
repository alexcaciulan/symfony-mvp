<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * One line of the state a confirmation dialog states before the lawyer closes a
 * fatal deadline. The label is always a translation key; the value is either a
 * translation key of its own (an enum label) or an already formatted literal (an
 * amount, a date), which is what {@see self::$valueIsTranslationKey} says.
 */
final readonly class DeadlineConfirmationFact
{
    public function __construct(
        public string $labelKey,
        public string $value,
        public bool $valueIsTranslationKey = false,
    ) {}
}
