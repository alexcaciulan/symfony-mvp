<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Entity\BnrExchangeRate;
use App\Repository\BnrExchangeRateRepository;
use App\Service\Calculation\CurrencyConverter;
use PHPUnit\Framework\TestCase;

class CurrencyConverterTest extends TestCase
{
    public function testRonIsIdentityWithoutLookup(): void
    {
        $converter = new CurrencyConverter($this->makeRepo(null));

        $result = $converter->convertToRon(1_500.0, 'RON', new \DateTimeImmutable('2025-01-02'));

        $this->assertTrue($result->identity);
        $this->assertSame(1_500.0, $result->ronAmount);
        $this->assertSame(1.0, $result->rate);
        $this->assertNull($result->rateDate);
        $this->assertSame('RON', $result->originalCurrency);
    }

    public function testEurIsConvertedAtBnrRate(): void
    {
        $converter = new CurrencyConverter($this->makeRepo(
            $this->makeRate('EUR', '2025-01-02', '4.9760'),
        ));

        // Invoice a few days after the rate (weekend fallback, within tolerance).
        $result = $converter->convertToRon(47_500.0, 'EUR', new \DateTimeImmutable('2025-01-06'));

        $this->assertFalse($result->identity);
        $this->assertSame('EUR', $result->originalCurrency);
        $this->assertSame(47_500.0, $result->originalAmount);
        $this->assertSame(4.976, $result->rate);
        $this->assertEqualsWithDelta(236_360.0, $result->ronAmount, 0.001);
        $this->assertEquals(new \DateTimeImmutable('2025-01-02'), $result->rateDate);
    }

    public function testConversionRoundsToTwoDecimals(): void
    {
        $converter = new CurrencyConverter($this->makeRepo(
            $this->makeRate('EUR', '2025-01-02', '4.9763'),
        ));

        $result = $converter->convertToRon(100.0, 'EUR', new \DateTimeImmutable('2025-01-02'));

        // 100 * 4.9763 = 497.63
        $this->assertSame(497.63, $result->ronAmount);
    }

    public function testMissingRateThrows(): void
    {
        $converter = new CurrencyConverter($this->makeRepo(null));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exception.calculation.exchange_rate_missing');

        $converter->convertToRon(100.0, 'EUR', new \DateTimeImmutable('2025-01-02'));
    }

    public function testStaleRateBeyondToleranceThrows(): void
    {
        // Only an old rate exists; the invoice date is months later. Rather than
        // convert at a stale rate, the converter treats it as unavailable.
        $converter = new CurrencyConverter($this->makeRepo(
            $this->makeRate('EUR', '2025-01-02', '4.9760'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exception.calculation.exchange_rate_missing');

        $converter->convertToRon(1_000.0, 'EUR', new \DateTimeImmutable('2025-05-01'));
    }

    private function makeRate(string $currency, string $date, string $rate): BnrExchangeRate
    {
        $config = new BnrExchangeRate();
        $config->setCurrency($currency);
        $config->setRateDate(new \DateTimeImmutable($date));
        $config->setRate($rate);

        return $config;
    }

    private function makeRepo(?BnrExchangeRate $rate): BnrExchangeRateRepository
    {
        return new class($rate) extends BnrExchangeRateRepository {
            public function __construct(private ?BnrExchangeRate $rate)
            {
                // intentionally skip parent constructor — only findRateValidAt() is exercised
            }

            public function findRateValidAt(string $currency, \DateTimeInterface $date): ?BnrExchangeRate
            {
                return $this->rate;
            }
        };
    }
}
