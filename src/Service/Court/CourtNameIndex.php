<?php

namespace App\Service\Court;

use App\Entity\Court;

/**
 * Looks up courts by name tolerantly, so imports survive spelling drift
 * (diacritics, spacing, casing) between the JSON data files and the database.
 *
 * Exact names win over normalized ones: when two distinct courts normalize to
 * the same key, an exact hit is still unambiguous, and only a normalized-only
 * lookup is reported as ambiguous rather than silently resolved.
 */
final class CourtNameIndex
{
    /** @var array<string, Court> */
    private array $byExactName = [];

    /** @var array<string, list<Court>> */
    private array $byNormalizedName = [];

    /** @param iterable<Court> $courts */
    public function __construct(iterable $courts)
    {
        foreach ($courts as $court) {
            $name = $court->getName();
            if ($name === null) {
                continue;
            }

            $this->byExactName[$name] = $court;

            $normalized = LocalityNormalizer::normalize($name);
            if ($normalized !== null) {
                $this->byNormalizedName[$normalized][] = $court;
            }
        }
    }

    /**
     * Returns the matching court, or null when there is no match or the name is
     * ambiguous. Callers that need to tell those two apart use {@see isAmbiguous()}.
     */
    public function find(string $name): ?Court
    {
        if (isset($this->byExactName[$name])) {
            return $this->byExactName[$name];
        }

        $candidates = $this->normalizedCandidates($name);

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    public function isAmbiguous(string $name): bool
    {
        if (isset($this->byExactName[$name])) {
            return false;
        }

        return count($this->normalizedCandidates($name)) > 1;
    }

    /** @return list<Court> */
    private function normalizedCandidates(string $name): array
    {
        $normalized = LocalityNormalizer::normalize($name);
        if ($normalized === null) {
            return [];
        }

        return $this->byNormalizedName[$normalized] ?? [];
    }
}
