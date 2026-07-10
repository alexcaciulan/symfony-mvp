<?php

declare(strict_types=1);

namespace App\Service\Calculation;

use App\DTO\Calculation\CurrencyConversionResult;
use App\Repository\BnrExchangeRateRepository;

/**
 * Converts a foreign-currency claim amount to RON at the BNR reference rate of a
 * given date (in practice, the invoice emission date). RON in, RON out, so the
 * rest of the calculation pipeline works exclusively in RON.
 */
final class CurrencyConverter
{
    /**
     * Max acceptable gap (calendar days) between the invoice date and the BNR
     * rate actually applied. BNR does not publish on weekends/holidays, so a
     * few days' gap is a legitimate fallback to the last official rate; beyond
     * this the rate for that period was never imported (e.g. the daily import
     * failed) and converting at a months-old rate would misstate the claim.
     */
    private const MAX_RATE_STALENESS_DAYS = 7;

    public function __construct(
        private BnrExchangeRateRepository $rateRepository,
    ) {}

    /**
     * @throws \RuntimeException when no BNR rate is available for the currency on
     *                           or before the given date
     */
    public function convertToRon(
        float $amount,
        string $currency,
        \DateTimeInterface $date,
    ): CurrencyConversionResult {
        if ($currency === 'RON') {
            return new CurrencyConversionResult($amount, 'RON', $amount, 1.0, null, true);
        }

        $config = $this->rateRepository->findRateValidAt($currency, $date);
        if ($config === null) {
            throw new \RuntimeException('exception.calculation.exchange_rate_missing');
        }

        // Freshness guard: reject a rate too far from the invoice date rather
        // than silently converting at a stale one. Surfaces as "rate unavailable".
        if ((int) $config->getRateDate()->diff($date)->days > self::MAX_RATE_STALENESS_DAYS) {
            throw new \RuntimeException('exception.calculation.exchange_rate_missing');
        }

        $rate = (float) $config->getRate();

        return new CurrencyConversionResult(
            originalAmount: $amount,
            originalCurrency: $currency,
            ronAmount: round($amount * $rate, 2),
            rate: $rate,
            rateDate: $config->getRateDate(),
            identity: false,
        );
    }
}
