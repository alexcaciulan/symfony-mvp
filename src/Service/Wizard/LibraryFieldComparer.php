<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\Service\Extraction\ConflictValueEquivalence;
use App\Service\Party\OnrcNumber;

/**
 * Decides whether a step value and a library value are one value written two
 * ways, so the wizard only asks the lawyer about real differences.
 */
final class LibraryFieldComparer
{
    public function __construct(
        private readonly ConflictValueEquivalence $valueEquivalence = new ConflictValueEquivalence(),
    ) {}

    /**
     * One value written two ways is not a difference to ask the lawyer about:
     * a registration number in its classic and compact form, or a name, an
     * address or a bank spelled with other diacritics and punctuation. The
     * same rule decides what the documents panel asks.
     */
    public function same(string $field, string $mine, string $theirs): bool
    {
        if ($mine === $theirs) {
            return true;
        }
        if ($field === 'onrcNumber') {
            return OnrcNumber::sameRegistration($mine, $theirs);
        }
        $equivalenceField = ['addressCounty' => 'county', 'addressLocality' => 'locality'][$field] ?? $field;

        return $this->valueEquivalence->same($equivalenceField, $mine, $theirs);
    }
}
