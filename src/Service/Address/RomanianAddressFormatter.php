<?php

declare(strict_types=1);

namespace App\Service\Address;

use App\Service\Court\LocalityNormalizer;
use App\Service\Court\RomanianAddressNormalizer;
use App\Util\RomanianDiacritics;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns {@see AddressParts} into the single address line the lawyer sees in the
 * wizard and that gets printed verbatim into the somaţie and the cerere de OP.
 *
 * The writing convention is the one the lawyer approved on the AI-extraction
 * output: full artery word ("Strada"), Title Case labels, components separated
 * by a comma, and no repetition of the locality or the county.
 *
 * Labels are translated with the locale pinned to Romanian: the result is the
 * body of a Romanian court document, so it must not follow the UI language of
 * whoever happens to be logged in.
 */
final class RomanianAddressFormatter
{
    private const DOCUMENT_LOCALE = 'ro';

    /**
     * Abbreviations ANAF prepends to `sdenumire_Strada`, expanded to the full
     * word used in court documents. Keys are folded (diacritics stripped,
     * lowercased) because ANAF writes "Şos." with a cedilla, which never
     * matches an ASCII key. An abbreviation outside this closed map is left
     * untouched rather than guessed at.
     */
    private const ARTERY_EXPANSIONS = [
        'str.' => 'Strada',
        'str' => 'Strada',
        'b-dul' => 'Bulevardul',
        'bd.' => 'Bulevardul',
        'bd' => 'Bulevardul',
        'bdul' => 'Bulevardul',
        'bld.' => 'Bulevardul',
        'bld' => 'Bulevardul',
        'cal.' => 'Calea',
        'cal' => 'Calea',
        'sos.' => 'Șoseaua',
        'sos' => 'Șoseaua',
        'p-ta.' => 'Piața',
        'p-ta' => 'Piața',
        'pta.' => 'Piața',
        'pta' => 'Piața',
        'ale.' => 'Aleea',
        'ale' => 'Aleea',
        'int.' => 'Intrarea',
        'int' => 'Intrarea',
        'drm.' => 'Drumul',
        'drm' => 'Drumul',
        'spl.' => 'Splaiul',
        'spl' => 'Splaiul',
        'prel.' => 'Prelungirea',
        'prel' => 'Prelungirea',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    /**
     * Components appear in a fixed order and an absent one is dropped whole, so
     * the result never carries an orphan comma or a dangling label.
     */
    public function format(AddressParts $parts): string
    {
        $components = [];

        if ($parts->street !== null) {
            $components[] = $this->expandArtery($parts->street);
        }
        if ($parts->streetNumber !== null) {
            $components[] = $this->label('number') . ' ' . $parts->streetNumber;
        }
        if ($parts->block !== null) {
            $components[] = $this->label('block') . ' ' . $parts->block;
        }
        if ($parts->staircase !== null) {
            $components[] = $this->label('staircase') . ' ' . $parts->staircase;
        }
        if ($parts->floor !== null) {
            $components[] = $this->label('floor') . ' ' . $parts->floor;
        }
        if ($parts->apartment !== null) {
            $components[] = $this->label('apartment') . ' ' . $parts->apartment;
        }
        foreach ($parts->details as $detail) {
            $components[] = $detail;
        }
        if ($parts->postalCode !== null) {
            $components[] = $this->label('postal_code') . ' ' . $parts->postalCode;
        }

        return RomanianDiacritics::toCommaBelow(implode(', ', $components));
    }

    /**
     * Address line for postal communication of procedural acts: the street-level
     * string plus the locality and the county.
     *
     * Each of the two is appended only when it is not already a component of the
     * address, and the county is dropped when it merely repeats the locality
     * (county seats such as Sibiu, Brăila, Iaşi, and every Bucharest sector).
     * That also cleans up rows saved before this fix, which carry the locality
     * and the county baked into the address string, without a data migration.
     */
    public function formatMailingLine(?string $address, ?string $locality, ?string $county): string
    {
        $address = trim((string) $address);
        $components = $address !== '' ? array_map(trim(...), explode(',', $address)) : [];

        $normalizedCounty = RomanianAddressNormalizer::normalizeCounty($county);

        // Compared per whole component, never as a substring. The first
        // component is the street and is skipped: the commune of Traian has
        // "Traian" as its street name too, and matching it would drop the UAT
        // off the envelope.
        $seen = [];
        foreach (array_slice($components, 1) as $component) {
            foreach ([
                LocalityNormalizer::normalize($component),
                RomanianAddressNormalizer::normalizeCounty($component),
                RomanianAddressNormalizer::normalizeLocality($component, $normalizedCounty),
            ] as $variant) {
                if ($variant !== null) {
                    $seen[$variant] = true;
                }
            }
        }

        $line = $components;

        $administrative = [
            [$locality, RomanianAddressNormalizer::normalizeLocality($locality, $normalizedCounty)],
            [$county, $normalizedCounty],
        ];

        foreach ($administrative as [$value, $normalized]) {
            $value = trim((string) $value);
            if ($value === '' || $normalized === null || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $line[] = $value;
        }

        return RomanianDiacritics::toCommaBelow(implode(', ', array_filter($line, static fn (string $c): bool => $c !== '')));
    }

    /**
     * "Str. Răsăritului" becomes "Strada Răsăritului". The lookup runs on the
     * folded first token so the cedilla and comma-below spellings of "Şos."
     * both hit the same entry.
     */
    private function expandArtery(string $street): string
    {
        $parts = preg_split('/\s+/u', trim($street), 2);
        if ($parts === false || count($parts) < 2) {
            return $street;
        }

        [$prefix, $rest] = $parts;
        $folded = LocalityNormalizer::normalize($prefix);

        return isset(self::ARTERY_EXPANSIONS[$folded])
            ? self::ARTERY_EXPANSIONS[$folded] . ' ' . $rest
            : $street;
    }

    private function label(string $part): string
    {
        return $this->translator->trans('address.part.' . $part, [], 'messages', self::DOCUMENT_LOCALE);
    }
}
