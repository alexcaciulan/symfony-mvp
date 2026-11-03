<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Case\ClaimPositionInput;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\BnrExchangeRate;
use App\Repository\BnrExchangeRateRepository;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Case\ClaimItemFactory;
use PHPUnit\Framework\TestCase;

class ClaimItemFactoryTest extends TestCase
{
    public function testTheSameInvoiceWrittenTwoWaysCollidesOnOneKey(): void
    {
        $factory = $this->factory();
        $date = new \DateTimeImmutable('2025-03-01');

        $this->assertSame(
            $factory->dedupKey('FF 0012/2025', $date, 100.0, 'RON'),
            $factory->dedupKey('FF12/2025', $date, 100.0, 'RON'),
        );
    }

    public function testDifferentInvoiceNumbersDoNotCollide(): void
    {
        $factory = $this->factory();
        $date = new \DateTimeImmutable('2025-03-01');

        $this->assertNotSame(
            $factory->dedupKey('FF 12/2025', $date, 100.0, 'RON'),
            $factory->dedupKey('FF 13/2025', $date, 100.0, 'RON'),
        );
    }

    public function testWithoutANumberTheKeyFallsBackToDateAmountAndCurrency(): void
    {
        $factory = $this->factory();
        $date = new \DateTimeImmutable('2025-03-01');

        $weak = $factory->dedupKey(null, $date, 100.0, 'RON');

        $this->assertStringStartsWith('amt:', $weak);
        $this->assertSame($weak, $factory->dedupKey('   ', $date, 100.0, 'RON'));
        $this->assertNotSame($weak, $factory->dedupKey(null, $date, 100.0, 'EUR'));
    }

    public function testARonClaimNeedsNoConversion(): void
    {
        $row = $this->factory()->rowFromClaim(new Step3ClaimData(
            amount: 1500.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('2026-03-01'),
            invoiceNumber: 'FF-1',
            invoiceDate: new \DateTimeImmutable('2026-02-01'),
        ));

        $this->assertNotNull($row);
        $this->assertSame(1500.0, $row->amountRon);
        $this->assertFalse($row->needsManualFx);
    }

    public function testAForeignInvoiceIsConvertedAtTheRateOfItsOwnDate(): void
    {
        $row = $this->factory()->rowFromClaim(new Step3ClaimData(
            amount: 100.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2026-03-01'),
            invoiceNumber: 'FF-2',
            invoiceDate: new \DateTimeImmutable('2026-02-01'),
        ));

        $this->assertNotNull($row);
        $this->assertSame(500.0, $row->amountRon);
        $this->assertSame(5.0, $row->exchangeRate);
        $this->assertFalse($row->needsManualFx);
    }

    public function testAnUnavailableRateFlagsThePositionInsteadOfBreakingTheWizard(): void
    {
        // The historical rates for this date were never imported; the converter
        // refuses to convert at a rate a year off, which is right.
        $row = $this->factory()->rowFromClaim(new Step3ClaimData(
            amount: 100.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2023-06-01'),
            invoiceNumber: 'FF-3',
            invoiceDate: new \DateTimeImmutable('2023-05-01'),
        ));

        $this->assertNotNull($row);
        $this->assertTrue($row->needsManualFx);
        $this->assertNull($row->amountRon);
        $this->assertContains('wizard.step3.claim_items.warning.fx_unavailable', $row->warningKeys);
    }

    public function testAFlaggedPositionAlwaysNeedsItsOwnConfirmation(): void
    {
        $row = $this->factory()->rowFromClaim(new Step3ClaimData(
            amount: 100.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2023-06-01'),
            invoiceDate: new \DateTimeImmutable('2023-05-01'),
        ));

        $this->assertNotNull($row);
        $this->assertTrue($row->requiresIndividualConfirmation(0.6));
    }

    public function testAZeroClaimProducesNoPosition(): void
    {
        $this->assertNull($this->factory()->rowFromClaim(new Step3ClaimData(amount: 0.0)));
    }

    public function testARowTypedOnTheCasePageIsConfirmedAndKeyedWithTheIssuer(): void
    {
        $factory = $this->factory();
        $row = $factory->rowFromInput(new ClaimPositionInput(
            documentNumber: ' FF 0012/2025 ',
            documentDate: new \DateTimeImmutable('2026-02-01'),
            dueDate: new \DateTimeImmutable('2026-03-01'),
            amount: 1500.0,
            currency: 'RON',
            causeReference: ' Contract 7/2024 ',
        ), 'RO12345678');

        $this->assertTrue($row->confirmed);
        $this->assertSame(1500.0, $row->amountRon);
        $this->assertSame('FF 0012/2025', $row->documentNumber);
        $this->assertSame('Contract 7/2024', $row->causeReference);
        $this->assertSame($factory->dedupKey('FF12/2025', new \DateTimeImmutable('2026-02-01'), 1500.0, 'RON', 'RO12345678'), $row->dedupKey);
        $this->assertNotSame($factory->dedupKey('FF12/2025', new \DateTimeImmutable('2026-02-01'), 1500.0, 'RON'), $row->dedupKey, 'The issuer qualifies the key.');
    }

    public function testARowInEuroIsConvertedAtTheRateOfItsInvoiceDate(): void
    {
        $row = $this->factory()->rowFromInput(new ClaimPositionInput(
            documentNumber: 'FF-2',
            documentDate: new \DateTimeImmutable('2026-02-01'),
            dueDate: new \DateTimeImmutable('2026-03-01'),
            amount: 100.0,
            currency: 'EUR',
        ), null);

        $this->assertSame(500.0, $row->amountRon);
        $this->assertSame(5.0, $row->exchangeRate);
        $this->assertFalse($row->needsManualFx);
    }

    public function testARowInEuroWithoutARateIsFlaggedForAManualRate(): void
    {
        $row = $this->factory()->rowFromInput(new ClaimPositionInput(
            documentNumber: 'FF-3',
            documentDate: new \DateTimeImmutable('2020-02-01'),
            dueDate: new \DateTimeImmutable('2020-03-01'),
            amount: 100.0,
            currency: 'EUR',
        ), null);

        $this->assertNull($row->amountRon);
        $this->assertTrue($row->needsManualFx);
        $this->assertContains('wizard.step3.claim_items.warning.fx_unavailable', $row->warningKeys);
    }

    private function factory(): ClaimItemFactory
    {
        return new ClaimItemFactory($this->documentRepository(), $this->converter());
    }

    private function converter(): CurrencyConverter
    {
        $rate = new BnrExchangeRate();
        $rate->setCurrency('EUR');
        $rate->setRate('5.0000');
        $rate->setRateDate(new \DateTimeImmutable('2026-01-30'));

        $repository = new class([$rate]) extends BnrExchangeRateRepository {
            /** @param list<BnrExchangeRate> $rates */
            public function __construct(private array $rates)
            {
                // Only findRateValidAt() is exercised.
            }

            public function findRateValidAt(string $currency, \DateTimeInterface $date): ?BnrExchangeRate
            {
                $match = null;
                foreach ($this->rates as $rate) {
                    if ($rate->getCurrency() === $currency && $rate->getRateDate() <= $date) {
                        $match = $rate;
                    }
                }

                return $match;
            }
        };

        return new CurrencyConverter($repository);
    }

    private function documentRepository(): DocumentRepository
    {
        return new class extends DocumentRepository {
            public function __construct()
            {
                // No document is loaded on the manual-claim path.
            }

            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
            {
                return [];
            }
        };
    }
}
