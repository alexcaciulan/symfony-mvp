<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Service\Case\CaseFxTrace;
use PHPUnit\Framework\TestCase;

/**
 * The case-level exchange trace the recalculator derives from the positions,
 * through {@see CaseFxTrace}.
 */
final class ClaimRecalculatorFxTest extends TestCase
{
    private CaseFxTrace $trace;

    protected function setUp(): void
    {
        $this->trace = new CaseFxTrace();
    }

    public function testASingleConvertedPositionWritesItsConversionOnTheCase(): void
    {
        $case = new LegalCase();
        $case->addClaimItem($this->item('1000.00', 'EUR', '4975.00', '4.9750', '2026-01-15')->setPaidAmount('100.00'));

        $change = $this->trace->refresh($case);

        self::assertSame('900.00', $case->getOriginalAmount(), 'The trace states what is still unpaid, as the table does.');
        self::assertSame('EUR', $case->getOriginalCurrency());
        self::assertSame('4.9750', $case->getExchangeRate());
        self::assertSame('2026-01-15', $case->getExchangeRateDate()?->format('Y-m-d'));
        self::assertNotNull($change);
        self::assertNull($change['from']['originalCurrency']);
        self::assertSame('EUR', $change['to']['originalCurrency']);
    }

    public function testTwoPositionsInTheSameCurrencyClearTheTrace(): void
    {
        $case = $this->caseWithTrace();
        $case->addClaimItem($this->item('1000.00', 'EUR', '4975.00', '4.9750', '2026-01-15'));
        $case->addClaimItem($this->item('500.00', 'EUR', '2487.50', '4.9750', '2026-01-15'));

        $change = $this->trace->refresh($case);

        self::assertNull($case->getOriginalAmount());
        self::assertNull($case->getOriginalCurrency());
        self::assertNull($case->getExchangeRate());
        self::assertNull($case->getExchangeRateDate());
        self::assertSame('EUR', $change['from']['originalCurrency'] ?? null);
    }

    public function testMixedCurrenciesClearTheTrace(): void
    {
        $case = $this->caseWithTrace();
        $case->addClaimItem($this->item('1000.00', 'EUR', '4975.00', '4.9750', '2026-01-15'));
        $case->addClaimItem($this->item('700.00', 'RON', '700.00', null, null));

        $this->trace->refresh($case);

        self::assertNull($case->getOriginalCurrency());
        self::assertNull($case->getExchangeRate());
    }

    public function testASingleRonPositionClearsTheTrace(): void
    {
        $case = $this->caseWithTrace();
        $case->addClaimItem($this->item('700.00', 'RON', '700.00', null, null));

        $change = $this->trace->refresh($case);

        self::assertNotNull($change);
        self::assertNull($case->getOriginalCurrency());
    }

    public function testOnlyThePositionsThatCountAreConsidered(): void
    {
        $case = new LegalCase();
        $case->addClaimItem($this->item('1000.00', 'EUR', '4975.00', '4.9750', '2026-01-15'));
        $case->addClaimItem($this->item('500.00', 'USD', '2300.00', '4.6000', '2026-01-15')->setExcludedByLawyer(true));

        $this->trace->refresh($case);

        self::assertSame('EUR', $case->getOriginalCurrency());
        self::assertSame('1000.00', $case->getOriginalAmount());
    }

    public function testNothingToChangeReportsNull(): void
    {
        $case = new LegalCase();
        $case->addClaimItem($this->item('700.00', 'RON', '700.00', null, null));

        self::assertNull($this->trace->refresh($case));
    }

    private function caseWithTrace(): LegalCase
    {
        $case = new LegalCase();
        $case->setOriginalAmount('1000.00');
        $case->setOriginalCurrency('EUR');
        $case->setExchangeRate('4.9750');
        $case->setExchangeRateDate(new \DateTimeImmutable('2026-01-15'));

        return $case;
    }

    private function item(string $amount, string $currency, string $amountRon, ?string $rate, ?string $rateDate): ClaimItem
    {
        $item = new ClaimItem();
        $item->setAmount($amount);
        $item->setCurrency($currency);
        $item->setAmountRon($amountRon);
        $item->setExchangeRate($rate);
        $item->setExchangeRateDate($rateDate !== null ? new \DateTimeImmutable($rateDate) : null);
        $item->setDueDate(new \DateTimeImmutable('2026-02-01'));
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('amt:' . $amount . $currency);

        return $item;
    }
}
