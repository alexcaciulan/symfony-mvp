<?php

declare(strict_types=1);

namespace App\Tests\DTO\Wizard;

use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ContractualAccessoryLabel;
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

    public function testAccessoryLabelKeyFollowsThePenaltyType(): void
    {
        self::assertNull((new Step3ClaimData())->contractualAccessoryLabelKey(), 'Statutory branch is plain interest.');
        self::assertSame(
            ContractualAccessoryLabel::DEFAULT->label(),
            (new Step3ClaimData(penaltyType: PenaltyType::CONTRACTUAL))->contractualAccessoryLabelKey(),
        );
        self::assertSame(
            ContractualAccessoryLabel::DOBANZI_PENALIZATOARE->label(),
            (new Step3ClaimData(penaltyType: PenaltyType::CONTRACTUAL, contractualAccessoryLabel: ContractualAccessoryLabel::DOBANZI_PENALIZATOARE))->contractualAccessoryLabelKey(),
        );
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

    public function testOverlongPenaltyClauseIsRejectedOnTheContractualBranch(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            legalGround: LegalGroundCategory::CONTRACT_PRESTARI_SERVICII,
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualPenaltyRate: 0.1,
            penaltyClauseArticle: str_repeat('a', 101),
            penaltyClauseText: str_repeat('a', 10001),
        );

        $messages = $this->messageTemplates($this->validator->validate($dto));

        self::assertContains('wizard.step3.error.penalty_clause_article_too_long', $messages);
        self::assertContains('wizard.step3.error.penalty_clause_text_too_long', $messages);
    }

    public function testPenaltyClauseIsNotValidatedOnTheStatutoryBranch(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            legalGround: LegalGroundCategory::CONTRACT_PRESTARI_SERVICII,
            penaltyType: PenaltyType::LEGAL_PENALIZATOARE,
            penaltyClauseArticle: str_repeat('a', 101),
        );

        self::assertCount(0, $this->validator->validate($dto));
    }

    public function testOverlongNoticeNumberAndContractObjectAreRejected(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-1 day'),
            relationshipType: RelationshipType::COMERCIAL,
            legalGround: LegalGroundCategory::CONTRACT_PRESTARI_SERVICII,
            contractObject: str_repeat('a', 256),
            paymentNoticeNumber: str_repeat('1', 51),
        );

        $messages = $this->messageTemplates($this->validator->validate($dto));

        self::assertContains('wizard.step3.error.contract_object_too_long', $messages);
        self::assertContains('wizard.step3.error.payment_notice_number_too_long', $messages);
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
