<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * Compares the normalised page texts of a generated file with those of a signed
 * upload. Every word of generated page i has to appear, in order, among the
 * words of signed page i: a changed, missing or moved word fails, and words a
 * signing tool added (a flattened visible signature, a stamp) are reported as
 * inserted. Since every generated word has to be consumed, an inserted word can
 * never stand in for a deleted one. Pages are compared by position, so extra
 * signed pages can only follow the last generated page.
 */
final class PageTextComparison
{
    /**
     * @param list<string> $generated
     * @param list<string> $signed
     */
    public static function compare(array $generated, array $signed): ?PageComparisonResult
    {
        if (count($signed) < count($generated)) {
            return null;
        }

        $inserted = [];
        foreach ($generated as $i => $text) {
            $words = self::words($text);
            $signedWords = self::words($signed[$i]);
            if ($words === [] || $signedWords === []) {
                if ($words !== $signedWords) {
                    return null;
                }
                continue;
            }

            $extra = self::insertedWords($words, $signedWords);
            if ($extra === null) {
                return null;
            }
            if ($extra !== []) {
                $inserted[$i + 1] = $extra;
            }
        }

        return new PageComparisonResult($inserted, count($signed) - count($generated));
    }

    /**
     * Greedy leftmost matching, linear in both lengths. Returns the signed words
     * left over, or null when a generated word is not found in order.
     *
     * @param list<string> $words
     * @param list<string> $signedWords
     *
     * @return list<string>|null
     */
    private static function insertedWords(array $words, array $signedWords): ?array
    {
        $extra = [];
        $j = 0;
        $total = count($signedWords);
        foreach ($words as $word) {
            while ($j < $total && $signedWords[$j] !== $word) {
                $extra[] = $signedWords[$j];
                ++$j;
            }
            if ($j === $total) {
                return null;
            }
            ++$j;
        }

        return [...$extra, ...array_slice($signedWords, $j)];
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return $text === '' ? [] : explode(' ', $text);
    }
}
