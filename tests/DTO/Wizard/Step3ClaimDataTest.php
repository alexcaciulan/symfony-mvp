<?php

declare(strict_types=1);

namespace App\Tests\DTO\Wizard;

use App\DTO\Wizard\Step3ClaimData;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class Step3ClaimDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testValidClaimPasses(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            legalGround: LegalGroundCategory::CONTRACT_PRESTARI_SERVICII,
            description: 'Factura serie X nr. 1234',
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testNegativeAmountIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: -100.0,
            dueDate: new \DateTimeImmutable('-1 day'),
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step3.error.amount_positive', $messages);
    }

    public function testZeroAmountIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: 0.0,
            dueDate: new \DateTimeImmutable('-1 day'),
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step3.error.amount_positive', $messages);
    }

    public function testMissingAmountIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: null,
            dueDate: new \DateTimeImmutable('-1 day'),
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step3.error.amount_required', $messages);
    }

    public function testFutureDueDateIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            dueDate: new \DateTimeImmutable('+1 day'),
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step3.error.due_date_not_future', $messages);
    }

    public function testInvalidCurrencyIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            currency: 'USD',
            dueDate: new \DateTimeImmutable('-1 day'),
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step3.error.invalid_currency', $messages);
    }

    public function testMissingDueDateIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            dueDate: null,
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step3.error.due_date_required', $messages);
    }

    public function testContractualPenaltyWithoutRateIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            dueDate: new \DateTimeImmutable('-1 day'),
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualPenaltyRate: null,
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step3.error.penalty_rate_required', $messages);
    }

    public function testLegalPenaltyIgnoresMissingRate(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            dueDate: new \DateTimeImmutable('-1 day'),
            penaltyType: PenaltyType::LEGAL_PENALIZATOARE,
            contractualPenaltyRate: null,
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertNotContains('wizard.step3.error.penalty_rate_required', $messages);
    }

    public function testForeignCurrencyRequiresInvoiceDate(): void
    {
        $dto = new Step3ClaimData(
            amount: 1000.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            invoiceDate: null,
        );

        $violations = $this->validator->validate($dto);

        self::assertContains('wizard.step3.error.invoice_date_required_fx', $this->messageTemplates($violations));
    }

    public function testForeignCurrencyWithInvoiceDatePasses(): void
    {
        $dto = new Step3ClaimData(
            amount: 1000.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            invoiceDate: new \DateTimeImmutable('-10 days'),
        );

        $violations = $this->validator->validate($dto);

        self::assertNotContains('wizard.step3.error.invoice_date_required_fx', $this->messageTemplates($violations));
    }

    public function testRonDoesNotRequireInvoiceDate(): void
    {
        $dto = new Step3ClaimData(
            amount: 1000.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            invoiceDate: null,
        );

        $violations = $this->validator->validate($dto);

        self::assertNotContains('wizard.step3.error.invoice_date_required_fx', $this->messageTemplates($violations));
    }

    public function testFutureInvoiceDateIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: 1000.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            invoiceDate: new \DateTimeImmutable('+1 day'),
        );

        $violations = $this->validator->validate($dto);

        self::assertContains('wizard.step3.error.invoice_date_not_future', $this->messageTemplates($violations));
    }

    public function testContractualPenaltyRateTooHighIsRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            dueDate: new \DateTimeImmutable('-1 day'),
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualPenaltyRate: 9.0,
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step3.error.penalty_rate_too_high', $messages);
    }

    public function testValidContractualPenaltyPasses(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            legalGround: LegalGroundCategory::CONTRACT_PRESTARI_SERVICII,
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualPenaltyRate: 0.1,
            contractReference: 'art. 3 din Contract',
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    /**
     * @param \Symfony\Component\Validator\ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface> $violations
     * @return list<string>
     */
    private function messageTemplates(iterable $violations): array
    {
        $out = [];
        foreach ($violations as $v) {
            $out[] = $v->getMessageTemplate();
        }

        return $out;
    }
}
