<?php

namespace App\Tests\DTO;

use App\DTO\Case\Step3DefendantEntry;
use App\DTO\Case\Step3DefendantsData;
use App\Entity\LegalCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Step3DefendantsDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    private function createValidEntry(): Step3DefendantEntry
    {
        $entry = new Step3DefendantEntry();
        $entry->type = 'pf';
        $entry->name = 'Defendant Test';
        $entry->city = 'București';
        $entry->county = 'București';

        return $entry;
    }

    public function testValidationFailsWithZeroDefendants(): void
    {
        $dto = new Step3DefendantsData();
        $dto->defendants = [];

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testValidationPassesWithOneDefendant(): void
    {
        $dto = new Step3DefendantsData();
        $dto->defendants = [$this->createValidEntry()];

        $errors = $this->validator->validate($dto);
        $this->assertCount(0, $errors);
    }

    public function testValidationFailsWithMoreThanThree(): void
    {
        $dto = new Step3DefendantsData();
        $dto->defendants = [
            $this->createValidEntry(),
            $this->createValidEntry(),
            $this->createValidEntry(),
            $this->createValidEntry(),
        ];

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testCascadingValidationCatchesInvalidEntry(): void
    {
        $invalid = new Step3DefendantEntry();
        $invalid->name = null; // required

        $dto = new Step3DefendantsData();
        $dto->defendants = [$invalid];

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testToArrayConvertsAllEntries(): void
    {
        $dto = new Step3DefendantsData();
        $dto->defendants = [$this->createValidEntry(), $this->createValidEntry()];

        $array = $dto->toArray();
        $this->assertCount(2, $array);
        $this->assertSame('Defendant Test', $array[0]['name']);
    }

    public function testFromLegalCaseLoadsExistingDefendants(): void
    {
        $case = new LegalCase();
        $case->setDefendants([
            ['type' => 'pf', 'name' => 'John', 'city' => 'Cluj', 'county' => 'Cluj'],
            ['type' => 'pj', 'name' => 'SRL X', 'city' => 'Iasi', 'county' => 'Iasi'],
        ]);

        $dto = Step3DefendantsData::fromLegalCase($case);
        $this->assertCount(2, $dto->defendants);
        $this->assertSame('John', $dto->defendants[0]->name);
        $this->assertSame('pj', $dto->defendants[1]->type);
    }

    public function testFromLegalCaseCreatesEmptyEntryWhenNoData(): void
    {
        $case = new LegalCase();

        $dto = Step3DefendantsData::fromLegalCase($case);
        $this->assertCount(1, $dto->defendants);
        $this->assertNull($dto->defendants[0]->name);
    }
}
