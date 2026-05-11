<?php

declare(strict_types=1);

namespace App\Tests\Form\Wizard;

use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\Enum\PersonType;
use App\Form\Wizard\Step2DebtorsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class Step2DebtorsTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(FormFactoryInterface::class);
    }

    public function testSubmitWithOneValidDebtorIsValid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'debtors' => [
                [
                    'personType' => 'PJ',
                    'name' => 'SC Bar SRL',
                    'cui' => '14186770',
                    'address' => 'Bd. Test 2',
                ],
            ],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertInstanceOf(Step2DebtorsData::class, $dto);
        self::assertCount(1, $dto->debtors);
        self::assertInstanceOf(Step2DebtorEntry::class, $dto->debtors[0]);
    }

    public function testSubmitWithMultipleDebtorsIsValid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'debtors' => [
                ['personType' => 'PJ', 'name' => 'SC Bar SRL', 'cui' => '14186770', 'address' => 'Bd. Test 2'],
                ['personType' => 'PJ', 'name' => 'SC Baz SRL', 'cui' => '15193236', 'address' => 'Str. Test 3'],
            ],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertCount(2, $form->getData()->debtors);
    }

    public function testSubmitWithSixDebtorsIsInvalid(): void
    {
        $entries = [];
        for ($i = 0; $i < 6; $i++) {
            $entries[] = ['personType' => 'PJ', 'name' => "SC X{$i}", 'cui' => '14186770', 'address' => "Addr {$i}"];
        }

        $form = $this->buildForm();
        $form->submit(['debtors' => $entries]);

        self::assertFalse($form->isValid());
    }

    public function testSubmitWithInvalidEntryFailsRecursively(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'debtors' => [
                ['personType' => 'PJ' /* missing name + address */],
            ],
        ]);

        self::assertFalse($form->isValid());
    }

    public function testRemovingEntryWorksWithByReferenceFalse(): void
    {
        $seed = new Step2DebtorsData([
            new Step2DebtorEntry(personType: PersonType::PJ, name: 'A', cui: '14186770', address: 'X'),
            new Step2DebtorEntry(personType: PersonType::PJ, name: 'B', cui: '15193236', address: 'Y'),
        ]);

        $form = $this->buildForm($seed);
        // Submit with only the first entry — the second must be dropped via the
        // setter (this is what `by_reference: false` enables on the DTO).
        $form->submit([
            'debtors' => [
                ['personType' => 'PJ', 'name' => 'A', 'cui' => '14186770', 'address' => 'X'],
            ],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertCount(1, $form->getData()->debtors);
        self::assertSame('A', $form->getData()->debtors[0]->name);
    }

    private function buildForm(?Step2DebtorsData $data = null): FormInterface
    {
        return $this->factory->create(Step2DebtorsType::class, $data, ['csrf_protection' => false]);
    }
}
