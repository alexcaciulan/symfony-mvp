<?php

declare(strict_types=1);

namespace App\Tests\DTO\Case;

use App\DTO\Case\LegalCostsInput;
use App\Entity\LegalCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** The recovery costs follow the wizard's rules and messages. */
final class LegalCostsInputTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testValidCostsPass(): void
    {
        self::assertSame([], $this->messages(new LegalCostsInput(1500.0, 'RON', 10.0)));
        self::assertSame([], $this->messages(new LegalCostsInput(null, 'EUR', null)), 'Both fees are optional.');
    }

    public function testANegativeFixedFeeIsRefused(): void
    {
        self::assertSame(['wizard.step3.error.legal_costs_fixed_positive'], $this->messages(new LegalCostsInput(-1.0, 'RON', null)));
    }

    public function testASuccessFeeAboveAHundredIsRefused(): void
    {
        self::assertSame(['wizard.step3.error.legal_costs_percent_range'], $this->messages(new LegalCostsInput(null, 'RON', 101.0)));
    }

    public function testAnUnsupportedCurrencyIsRefused(): void
    {
        self::assertSame(['wizard.step3.error.invalid_currency'], $this->messages(new LegalCostsInput(100.0, 'USD', null)));
    }

    public function testFromCaseStartsFromTheStoredValues(): void
    {
        $case = new LegalCase();
        $case->setLegalCostsFixed('1500.00');
        $case->setLegalCostsCurrency('RON');
        $case->setLegalCostsSuccessPercent('7.50');

        $input = LegalCostsInput::fromCase($case);

        self::assertSame(1500.0, $input->legalCostsFixed);
        self::assertSame('RON', $input->legalCostsCurrency);
        self::assertSame(7.5, $input->legalCostsSuccessPercent);
    }

    /** @return list<string> */
    private function messages(LegalCostsInput $input): array
    {
        $messages = [];
        foreach ($this->validator->validate($input) as $violation) {
            $messages[] = (string) $violation->getMessageTemplate();
        }

        return $messages;
    }
}
