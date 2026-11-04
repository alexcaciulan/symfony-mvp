<?php

declare(strict_types=1);

namespace App\Tests\DTO\Case;

use App\DTO\Case\ClaimDetailsInput;
use App\Entity\LegalCase;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\PenaltyType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** The accessory rules of the case page are the wizard's, message for message. */
final class ClaimDetailsInputTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testAValidContractualConfigurationPasses(): void
    {
        $violations = $this->validator->validate($this->contractual(rate: 0.1, cap: 10.0));

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testAContractualModeWithoutRateIsRefused(): void
    {
        self::assertSame(['wizard.step3.error.penalty_rate_required'], $this->messages($this->contractual(rate: null)));
    }

    public function testARateAboveFivePercentADayIsRefused(): void
    {
        self::assertSame(['wizard.step3.error.penalty_rate_too_high'], $this->messages($this->contractual(rate: 6.0)));
    }

    public function testACapAboveAHundredPercentIsRefused(): void
    {
        self::assertSame(['wizard.step3.error.penalty_cap_too_high'], $this->messages($this->contractual(rate: 0.1, cap: 120.0)));
    }

    public function testTheStatutoryModeIgnoresRateAndCap(): void
    {
        $input = new ClaimDetailsInput(penaltyType: PenaltyType::LEGAL_PENALIZATOARE, contractualPenaltyRate: 9.0, contractualPenaltyCapPercent: 120.0);

        self::assertSame([], $this->messages($input));
    }

    public function testAMissingModeIsRefused(): void
    {
        self::assertSame(['wizard.step3.error.penalty_type_required'], $this->messages(new ClaimDetailsInput(penaltyType: null)));
    }

    public function testAFutureCutoffIsRefused(): void
    {
        $input = new ClaimDetailsInput(accessoryCutoffDate: new \DateTimeImmutable('+1 day'));

        self::assertSame(['wizard.step3.error.accessory_cutoff_future'], $this->messages($input));
    }

    public function testACutoffOnOrBeforeTheDueDateIsRefused(): void
    {
        $due = new \DateTimeImmutable('-30 days midnight');

        self::assertSame(
            ['wizard.step3.error.accessory_cutoff_before_due'],
            $this->messages(new ClaimDetailsInput(accessoryCutoffDate: $due, dueDate: $due)),
        );
        self::assertSame([], $this->messages(new ClaimDetailsInput(accessoryCutoffDate: $due->modify('+1 day'), dueDate: $due)));
    }

    public function testAnOverlongContractNumberOrObjectIsRefused(): void
    {
        self::assertSame(['wizard.step3.error.contract_number_too_long'], $this->messages(new ClaimDetailsInput(contractNumber: str_repeat('9', 101))));
        self::assertSame(['wizard.step3.error.contract_object_too_long'], $this->messages(new ClaimDetailsInput(contractObject: str_repeat('o', 256))));
    }

    public function testFromCaseStartsFromTheStoredValues(): void
    {
        $case = new LegalCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $case->setContractualPenaltyRate('0.150');
        $case->setContractualPenaltyCapPercent('12.50');
        $case->setContractualAccessoryLabel(ContractualAccessoryLabel::MAJORARI_INTARZIERE);
        $case->setContractDate(new \DateTime('2019-03-01'));
        $case->setContractNumber('58');
        $case->setDueDate(new \DateTime('2025-01-10'));

        $input = ClaimDetailsInput::fromCase($case);

        self::assertSame(PenaltyType::CONTRACTUAL, $input->penaltyType);
        self::assertSame(0.15, $input->contractualPenaltyRate);
        self::assertSame(12.5, $input->contractualPenaltyCapPercent);
        self::assertSame(ContractualAccessoryLabel::MAJORARI_INTARZIERE, $input->contractualAccessoryLabel);
        self::assertSame('2019-03-01', $input->contractDate?->format('Y-m-d'));
        self::assertSame('58', $input->contractNumber);
        self::assertSame('2025-01-10', $input->dueDate?->format('Y-m-d'));
        self::assertSame(PenaltyType::LEGAL_PENALIZATOARE, ClaimDetailsInput::fromCase(new LegalCase())->penaltyType, 'A case without a mode reads as statutory.');
    }

    private function contractual(?float $rate, ?float $cap = null): ClaimDetailsInput
    {
        return new ClaimDetailsInput(
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualPenaltyRate: $rate,
            contractualPenaltyCapPercent: $cap,
            contractualAccessoryLabel: ContractualAccessoryLabel::DOBANZI_PENALIZATOARE,
        );
    }

    /** @return list<string> */
    private function messages(ClaimDetailsInput $input): array
    {
        $messages = [];
        foreach ($this->validator->validate($input) as $violation) {
            $messages[] = (string) $violation->getMessageTemplate();
        }

        return $messages;
    }
}
