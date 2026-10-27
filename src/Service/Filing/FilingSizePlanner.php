<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingPackagePart;

/**
 * Decides whether the package fits the court mailbox, needs compression, or has
 * to be split into annex volumes. Pure arithmetic over byte sizes.
 *
 * The budget per email is the encoded limit brought back to raw bytes (base64
 * and MIME add about 37 %) minus a reserve for the signature added later.
 * Email 1 always carries the petition (with the index) and the power of
 * attorney; annex volumes split only between documents, never inside one, so
 * the index ranges stay whole.
 */
final class FilingSizePlanner
{
    public const ENCODING_FACTOR = 1.37;
    /** Cover page with its embedded font, per volume. */
    public const DEFAULT_VOLUME_OVERHEAD = 60_000;
    /** Number overlay and page tree, per annex page. */
    public const DEFAULT_PAGE_OVERHEAD = 2_000;

    public const ERROR_FIRST_EMAIL_TOO_LARGE = 'first_email_too_large';
    public const ERROR_ANNEX_TOO_LARGE = 'annex_too_large';

    public static function budget(int $maxEmailBytes, int $signatureReserveBytes): int
    {
        return (int) floor($maxEmailBytes / self::ENCODING_FACTOR) - $signatureReserveBytes;
    }

    /**
     * Raw and encoded bytes of each email, counting a part's signed file when it has one.
     *
     * @param iterable<FilingPackagePart> $parts
     * @param array<int, list<string>>    $tokensPerEmail
     *
     * @return array<int, array{parts: list<string>, bytes: int, encodedBytes: int}>
     */
    public static function emailBytes(iterable $parts, array $tokensPerEmail): array
    {
        $bytesByToken = [];
        foreach ($parts as $part) {
            $bytesByToken[$part->getPartToken()] = $part->getSignedBytes() ?? $part->getUnsignedBytes();
        }

        $emails = [];
        foreach ($tokensPerEmail as $i => $tokens) {
            $bytes = 0;
            foreach ($tokens as $token) {
                $bytes += $bytesByToken[$token] ?? 0;
            }
            $emails[$i] = ['parts' => $tokens, 'bytes' => $bytes, 'encodedBytes' => (int) ceil($bytes * self::ENCODING_FACTOR)];
        }

        return $emails;
    }

    /**
     * @param list<array{id: int, bytes: int, pages: int}> $annexes in bundle order
     * @param bool $compressed whether the annexes were already compressed; before that,
     *                         anything over budget only asks for compression
     */
    public function plan(
        int $firstEmailBytes,
        array $annexes,
        int $budget,
        bool $compressed,
        int $volumeOverhead = self::DEFAULT_VOLUME_OVERHEAD,
        int $pageOverhead = self::DEFAULT_PAGE_OVERHEAD,
    ): FilingSizePlan {
        if ($firstEmailBytes > $budget) {
            return new FilingSizePlan($budget, error: self::ERROR_FIRST_EMAIL_TOO_LARGE);
        }

        $cost = static fn (array $a): int => $a['bytes'] + $a['pages'] * $pageOverhead;
        if ($annexes === []) {
            return new FilingSizePlan($budget, emailBytes: [$firstEmailBytes]);
        }

        $annexTotal = $volumeOverhead + array_sum(array_map($cost, $annexes));
        if ($firstEmailBytes + $annexTotal <= $budget) {
            return new FilingSizePlan(
                $budget,
                volumes: [array_column($annexes, 'id')],
                volumesInFirstEmail: 1,
                emailBytes: [$firstEmailBytes + $annexTotal],
            );
        }

        if (!$compressed) {
            return new FilingSizePlan($budget, needsCompression: true);
        }

        foreach ($annexes as $annex) {
            if ($volumeOverhead + $cost($annex) > $budget) {
                return new FilingSizePlan($budget, error: self::ERROR_ANNEX_TOO_LARGE, errorDocumentId: $annex['id']);
            }
        }

        // Leading annexes ride with the petition while they fit; then one volume per email.
        $volumes = [];
        $emailBytes = [$firstEmailBytes];
        $volumesInFirstEmail = 0;
        $current = [];
        $currentBytes = 0;
        $capacity = $budget - $firstEmailBytes;
        $inFirstEmail = true;

        foreach ($annexes as $annex) {
            $needed = $cost($annex) + ($current === [] ? $volumeOverhead : 0);
            if ($currentBytes + $needed > $capacity) {
                if ($current !== []) {
                    $volumes[] = $current;
                    if ($inFirstEmail) {
                        $volumesInFirstEmail = 1;
                        $emailBytes[0] += $currentBytes;
                    } else {
                        $emailBytes[] = $currentBytes;
                    }
                }
                $inFirstEmail = false;
                $capacity = $budget;
                $current = [];
                $currentBytes = 0;
                $needed = $cost($annex) + $volumeOverhead;
            }
            $current[] = $annex['id'];
            $currentBytes += $needed;
        }

        $volumes[] = $current;
        if ($inFirstEmail) {
            $volumesInFirstEmail = 1;
            $emailBytes[0] += $currentBytes;
        } else {
            $emailBytes[] = $currentBytes;
        }

        return new FilingSizePlan($budget, true, $volumes, $volumesInFirstEmail, $emailBytes);
    }
}
