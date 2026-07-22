<?php

declare(strict_types=1);

namespace App\Service\Case;

/**
 * Reads the two things an invoice can say about itself that change what may be
 * claimed from it: that it is a storno, and that part of it was already
 * settled.
 *
 * Neither is arithmetic. A stated deduction stays out of the sum until the
 * lawyer imputes it (Civil Code art. 1507-1509); the point of detecting it is
 * that the decision is theirs and cannot be theirs if nobody shows it to them.
 */
final class ClaimTextSignals
{
    private const CREDIT_NOTE_PATTERN =
        '/\b(storno|stornare|stornata|stornată|nota de credit|notă de credit|credit note)\b/u';

    private const DEDUCTION_PATTERN =
        '/\b(avans|aconto|acont|scazamant|scăzământ|retinere de garantie|reținere de garanție|'
        . 'garantie retinuta|garanție reținută|plata partiala|plată parțială|achitat partial|achitat parțial)\b/u';

    /**
     * A money movement into the account of the party that is owed. Read off a
     * bank statement, where a payment that settles part of the claim is visible
     * nowhere else: the invoice still states its full total, so without this the
     * lawyer is never asked to impute it.
     */
    private const INCOMING_PAYMENT_PATTERN =
        '/\b(incasare|încasare|incasari|încasări|incasat|încasat|plata primita|plată primită|'
        . 'creditare|alimentare cont|ordin de plata|ordin de plată|virament)\b/u';

    public static function mentionsCreditNote(?string ...$fragments): bool
    {
        return self::matches(self::CREDIT_NOTE_PATTERN, ...$fragments);
    }

    public static function mentionsDeduction(?string ...$fragments): bool
    {
        return self::matches(self::DEDUCTION_PATTERN, ...$fragments);
    }

    public static function mentionsIncomingPayment(?string ...$fragments): bool
    {
        return self::matches(self::INCOMING_PAYMENT_PATTERN, ...$fragments);
    }

    private static function matches(string $pattern, ?string ...$fragments): bool
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter(
            $fragments,
            static fn (?string $fragment): bool => $fragment !== null && $fragment !== '',
        ))));

        return $haystack !== '' && preg_match($pattern, $haystack) === 1;
    }
}
