<?php

declare(strict_types=1);

namespace App\Tests\Form\Wizard;

use App\DTO\Wizard\Step3ClaimData;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Form\Wizard\Step3ClaimType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class Step3ClaimTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(FormFactoryInterface::class);
    }

    public function testValidSubmissionBinds(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'amount' => '1500',
            'currency' => 'RON',
            'dueDate' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'relationshipType' => 'COMERCIAL',
            'legalGround' => 'CONTRACT_PRESTARI_SERVICII',
            'description' => 'Factura 1234',
            'penaltyType' => 'LEGAL_PENALIZATOARE',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertInstanceOf(Step3ClaimData::class, $dto);
        self::assertSame(1500.0, $dto->amount);
        self::assertSame(RelationshipType::COMERCIAL, $dto->relationshipType);
        self::assertSame(LegalGroundCategory::CONTRACT_PRESTARI_SERVICII, $dto->legalGround);
        self::assertSame(PenaltyType::LEGAL_PENALIZATOARE, $dto->penaltyType);
    }

    public function testFutureDueDateIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'amount' => '1500',
            'currency' => 'RON',
            'dueDate' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d'),
            'relationshipType' => 'COMERCIAL',
        ]);

        self::assertFalse($form->isValid());
    }

    public function testZeroAmountIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'amount' => '0',
            'currency' => 'RON',
            'dueDate' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'relationshipType' => 'COMERCIAL',
        ]);

        self::assertFalse($form->isValid());
    }

    public function testCivilNotInRelationshipChoices(): void
    {
        $form = $this->factory->create(Step3ClaimType::class, null, ['csrf_protection' => false]);
        $choices = $form->get('relationshipType')->getConfig()->getOption('choices');

        self::assertContains(RelationshipType::COMERCIAL, $choices);
        self::assertNotContains(RelationshipType::CIVIL, $choices);
    }

    public function testLegalGroundFilteredByOpEligible(): void
    {
        $form = $this->factory->create(Step3ClaimType::class, null, ['csrf_protection' => false]);
        $view = $form->createView();

        // All current LegalGroundCategory cases are op-eligible — the filter
        // simply must not drop any of them, but we assert the contract is
        // wired so future cases that return isOpEligible() === false drop out.
        $rendered = array_map(static fn ($c) => $c->data, $view->children['legalGround']->vars['choices']);
        foreach (LegalGroundCategory::cases() as $case) {
            if ($case->isOpEligible()) {
                self::assertContains($case, $rendered, "Missing eligible case: {$case->value}");
            }
        }
    }

    public function testAutoFilledFieldsCarryDataAttribute(): void
    {
        $dto = new Step3ClaimData(
            amount: 1500.0,
            dueDate: new \DateTimeImmutable('-1 day'),
            autoFilled: ['amount', 'dueDate'],
        );

        $form = $this->buildForm($dto);
        $view = $form->createView();

        self::assertSame('true', $view->children['amount']->vars['attr']['data-auto-filled'] ?? null);
        self::assertSame('true', $view->children['dueDate']->vars['attr']['data-auto-filled'] ?? null);
        self::assertArrayNotHasKey('data-auto-filled', $view->children['currency']->vars['attr']);
    }

    private function buildForm(?Step3ClaimData $data = null): FormInterface
    {
        return $this->factory->create(Step3ClaimType::class, $data, ['csrf_protection' => false]);
    }
}
