<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * Hash of the case sources a package was built from, and which of them changed.
 * Pure: the manifest is built by FilingSourceManifestBuilder.
 */
final class FilingPackageFingerprint
{
    /**
     * Manifest components in the order a change is reported: the first one that
     * differs names the reason.
     */
    public const COMPONENTS = [
        'petitionSha256' => 'petition',
        'claim' => 'claim_totals',
        'accessoryReferenceDate' => 'interest_date',
        'powerOfAttorney' => 'power_of_attorney',
        'annexes' => 'annexes',
        'stampDuty' => 'stamp_duty',
        'courtId' => 'court',
        'opisPlacement' => 'opis_placement',
    ];

    /**
     * @param array<string, mixed> $manifest
     */
    public static function hash(array $manifest): string
    {
        return hash('sha256', json_encode(self::canonical($manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Reason code of the first component that differs, null when none does.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    public static function diff(array $old, array $new): ?string
    {
        $old = self::canonical($old);
        $new = self::canonical($new);
        foreach (self::COMPONENTS as $key => $reason) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                return $reason;
            }
        }

        return $old === $new ? null : 'other';
    }

    /**
     * Annexes sorted by id and keys sorted recursively, so the hash depends on
     * the content and not on the order the inputs arrived in.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    public static function canonical(array $manifest): array
    {
        if (isset($manifest['annexes']) && is_array($manifest['annexes'])) {
            $annexes = $manifest['annexes'];
            usort($annexes, static fn (array $a, array $b): int => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
            $manifest['annexes'] = $annexes;
        }

        return self::sortKeys($manifest);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
