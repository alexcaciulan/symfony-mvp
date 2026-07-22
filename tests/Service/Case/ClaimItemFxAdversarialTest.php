<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Wizard\Step3ClaimData;
use App\Entity\BnrExchangeRate;
use App\Repository\BnrExchangeRateRepository;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Case\ClaimItemFactory;
use App\Service\Case\ClaimTotalsService;
use PHPUnit\Framework\TestCase;

/**
 * Foreign-currency positions when the NBR rate is not there.
 *
 * The rate table holds barely anything before 2026, so an old EUR invoice is
 * the normal case for the freshness guard, not an exotic one. It must never
 * take the wizard down and never be converted at a rate that misstates the
 * claim; the position is flagged, kept out of the totals, and reported.
 */
final class ClaimItemFxAdversarialTest extends TestCase
{
    public function testAnEurPositionWithNoRateForItsDateIsFlaggedInsteadOfThrowing(): void
    {
        $factory = $this->factory([]);

        $row = $factory->rowFromClaim(new Step3ClaimData(
            amount: 1000.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2024-03-31'),
            invoiceNumber: 'FF-EUR-1',
            invoiceDate: new \DateTimeImmutable('2024-03-01'),
        ));

        $this->assertNotNull($row);
        $this->assertTrue($row->needsManualFx);
        $this->assertNull($row->amountRon);
        $this->assertNull($row->exchangeRate);
        $this->assertContains('wizard.step3.claim_items.warning.fx_unavailable', $row->warningKeys);
        // The lawyer has to look at this one; the table-wide tick may not.
        $this->assertTrue($row->requiresIndividualConfirmation(0.8));
    }

    /**
     * A rate exists but is months away from the invoice date. Converting at it
     * would state a principal nobody can reproduce, so the guard must reject it
     * exactly as if there were no rate at all.
     */
    public function testARateFurtherThanAWeekFromTheInvoiceDateIsRefused(): void
    {
        $factory = $this->factory([$this->rate('EUR', '2024-01-05', '4.9700')]);

        $row = $factory->rowFromClaim(new Step3ClaimData(
            amount: 1000.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2024-04-30'),
            invoiceNumber: 'FF-EUR-2',
            invoiceDate: new \DateTimeImmutable('2024-04-01'),
        ));

        $this->assertNotNull($row);
        $this->assertTrue($row->needsManualFx);
        $this->assertNull($row->amountRon);
    }

    /**
     * A rate within the tolerance converts, once, at that rate.
     * 1000 EUR x 4,9700 = 4970,00 RON.
     */
    public function testARateWithinToleranceConvertsAtTheRateOfTheInvoiceDate(): void
    {
        $factory = $this->factory([$this->rate('EUR', '2024-03-29', '4.9700')]);

        $row = $factory->rowFromClaim(new Step3ClaimData(
            amount: 1000.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2024-04-30'),
            invoiceNumber: 'FF-EUR-3',
            invoiceDate: new \DateTimeImmutable('2024-04-01'),
        ));

        $this->assertNotNull($row);
        $this->assertFalse($row->needsManualFx);
        $this->assertSame(4970.0, $row->amountRon);
        $this->assertSame(4.97, $row->exchangeRate);
    }

    /**
     * Each position is converted at the rate of its own date. One rate applied
     * to a file spanning two years would misstate every invoice but one.
     */
    public function testEachPositionUsesTheRateOfItsOwnDate(): void
    {
        $factory = $this->factory([
            $this->rate('EUR', '2026-01-05', '4.9700'),
            $this->rate('EUR', '2026-06-05', '5.1000'),
        ]);

        $january = $factory->rowFromClaim(new Step3ClaimData(
            amount: 1000.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2026-02-06'),
            invoiceNumber: 'FF-EUR-JAN',
            invoiceDate: new \DateTimeImmutable('2026-01-06'),
        ));
        $june = $factory->rowFromClaim(new Step3ClaimData(
            amount: 1000.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2026-07-06'),
            invoiceNumber: 'FF-EUR-JUN',
            invoiceDate: new \DateTimeImmutable('2026-06-06'),
        ));

        $this->assertSame(4970.0, $january?->amountRon);
        $this->assertSame(5100.0, $june?->amountRon);
    }

    /**
     * A flagged position never reaches the principal, and the case is told
     * which position is waiting rather than quietly totalling without it.
     */
    public function testAFlaggedPositionStaysOutOfTheTotalsAndIsReported(): void
    {
        $factory = $this->factory([]);
        $totalsService = new ClaimTotalsService();

        $ron = $factory->rowFromClaim(new Step3ClaimData(
            amount: 2500.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('2026-01-31'),
            invoiceNumber: 'FF-RON-1',
            invoiceDate: new \DateTimeImmutable('2026-01-01'),
        ));
        $eur = $factory->rowFromClaim(new Step3ClaimData(
            amount: 1000.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2024-04-30'),
            invoiceNumber: 'FF-EUR-4',
            invoiceDate: new \DateTimeImmutable('2024-04-01'),
        ));
        $this->assertNotNull($ron);
        $this->assertNotNull($eur);
        $ron->confirmed = true;
        $eur->confirmed = true;

        $case = new \App\Entity\LegalCase();
        $items = $factory->materialize($case, [$ron, $eur]);
        $totals = $totalsService->totals($items);

        $this->assertSame(2500.0, $totals->principalRon);
        $this->assertSame(2, $totals->itemCount);
        $this->assertFalse($items[1]->countsTowardsClaim());
        $this->assertSame([$items[0]], $case->getCountingClaimItems());
    }

    /** @param list<BnrExchangeRate> $rates */
    private function factory(array $rates): ClaimItemFactory
    {
        $rateRepository = new class($rates) extends BnrExchangeRateRepository {
            /** @param list<BnrExchangeRate> $rates */
            public function __construct(private array $rates)
            {
                // Only findRateValidAt() is exercised; the parent needs a registry we do not have.
            }

            public function findRateValidAt(string $currency, \DateTimeInterface $date): ?BnrExchangeRate
            {
                $match = null;
                foreach ($this->rates as $rate) {
                    if ($rate->getCurrency() === $currency
                        && $rate->getRateDate() <= $date
                        && ($match === null || $rate->getRateDate() > $match->getRateDate())) {
                        $match = $rate;
                    }
                }

                return $match;
            }
        };

        $documentRepository = new class extends DocumentRepository {
            public function __construct()
            {
                // No document is loaded on the manual-claim path.
            }

            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
            {
                return [];
            }
        };

        return new ClaimItemFactory($documentRepository, new CurrencyConverter($rateRepository));
    }

    private function rate(string $currency, string $date, string $value): BnrExchangeRate
    {
        $rate = new BnrExchangeRate();
        $rate->setCurrency($currency);
        $rate->setRateDate(new \DateTimeImmutable($date));
        $rate->setRate($value);

        return $rate;
    }
}
