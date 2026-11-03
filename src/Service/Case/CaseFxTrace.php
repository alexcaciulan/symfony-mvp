<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\LegalCase;

/**
 * Owner of the case-level exchange trace (`originalAmount`, `originalCurrency`,
 * `exchangeRate`, `exchangeRateDate`), the figures the summons prints under a
 * total converted from a foreign currency.
 *
 * The trace describes one conversion, so it is derived only when exactly one
 * position counts and that position was converted. With several positions, or
 * none, it is cleared: the summons table states the conversion per position,
 * and a case-level rate next to a total mixing currencies would be a false one.
 */
final class CaseFxTrace
{
    /** @return array<string, ?string> */
    public function read(LegalCase $case): array
    {
        return [
            'originalAmount' => $case->getOriginalAmount(),
            'originalCurrency' => $case->getOriginalCurrency(),
            'exchangeRate' => $case->getExchangeRate(),
            'exchangeRateDate' => $case->getExchangeRateDate()?->format('Y-m-d'),
        ];
    }

    /**
     * Rewrites the trace from the positions that count. Returns the previous
     * and new values when something moved, null when nothing did.
     *
     * @return array{from: array<string, ?string>, to: array<string, ?string>}|null
     */
    public function refresh(LegalCase $case): ?array
    {
        $from = $this->read($case);
        $counting = $case->getCountingClaimItems();

        $only = count($counting) === 1 ? $counting[0] : null;
        if ($only !== null && $only->getCurrency() !== 'RON' && $only->getExchangeRate() !== null) {
            $case->setOriginalAmount(sprintf('%.2f', $only->outstandingAmount()));
            $case->setOriginalCurrency($only->getCurrency());
            $case->setExchangeRate($only->getExchangeRate());
            $case->setExchangeRateDate($only->getExchangeRateDate());
        } else {
            $case->setOriginalAmount(null);
            $case->setOriginalCurrency(null);
            $case->setExchangeRate(null);
            $case->setExchangeRateDate(null);
        }

        $to = $this->read($case);

        return $from === $to ? null : ['from' => $from, 'to' => $to];
    }
}
