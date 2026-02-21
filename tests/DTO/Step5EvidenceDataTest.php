<?php

namespace App\Tests\DTO;

use App\DTO\Case\Step5EvidenceData;
use App\DTO\Case\Step5WitnessEntry;
use App\Entity\LegalCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Step5EvidenceDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    private function createWitness(string $name = 'Witness'): Step5WitnessEntry
    {
        $w = new Step5WitnessEntry();
        $w->name = $name;
        $w->address = 'Test Address';

        return $w;
    }

    public function testValidDtoPassesValidation(): void
    {
        $dto = new Step5EvidenceData();
        $dto->evidenceDescription = 'Some evidence';

        $errors = $this->validator->validate($dto);
        $this->assertCount(0, $errors);
    }

    public function testValidationFailsWithMoreThanFiveWitnesses(): void
    {
        $dto = new Step5EvidenceData();
        for ($i = 0; $i < 6; $i++) {
            $dto->witnesses[] = $this->createWitness("Witness $i");
        }

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testValidationPassesWithFiveWitnesses(): void
    {
        $dto = new Step5EvidenceData();
        for ($i = 0; $i < 5; $i++) {
            $dto->witnesses[] = $this->createWitness("Witness $i");
        }

        $errors = $this->validator->validate($dto);
        $this->assertCount(0, $errors);
    }

    public function testToWitnessesArrayReturnsNullWhenNoWitnesses(): void
    {
        $dto = new Step5EvidenceData();
        $dto->hasWitnesses = false;

        $this->assertNull($dto->toWitnessesArray());
    }

    public function testToWitnessesArrayReturnsNullWhenHasWitnessesFalse(): void
    {
        $dto = new Step5EvidenceData();
        $dto->hasWitnesses = false;
        $dto->witnesses = [$this->createWitness()];

        $this->assertNull($dto->toWitnessesArray());
    }

    public function testToWitnessesArrayReturnsArrayWhenData(): void
    {
        $dto = new Step5EvidenceData();
        $dto->hasWitnesses = true;
        $dto->witnesses = [$this->createWitness('Ion'), $this->createWitness('Maria')];

        $array = $dto->toWitnessesArray();
        $this->assertNotNull($array);
        $this->assertCount(2, $array);
        $this->assertSame('Ion', $array[0]['name']);
        $this->assertSame('Maria', $array[1]['name']);
    }

    public function testFromLegalCaseLoadsData(): void
    {
        $case = new LegalCase();
        $case->setEvidenceDescription('Test evidence');
        $case->setHasWitnesses(true);
        $case->setRequestOralDebate(true);
        $case->setWitnesses([
            ['name' => 'Witness 1', 'address' => 'Addr 1', 'details' => null],
        ]);

        $dto = Step5EvidenceData::fromLegalCase($case);

        $this->assertSame('Test evidence', $dto->evidenceDescription);
        $this->assertTrue($dto->hasWitnesses);
        $this->assertTrue($dto->requestOralDebate);
        $this->assertCount(1, $dto->witnesses);
        $this->assertSame('Witness 1', $dto->witnesses[0]->name);
    }

    public function testFromLegalCaseHandlesNullWitnesses(): void
    {
        $case = new LegalCase();

        $dto = Step5EvidenceData::fromLegalCase($case);

        $this->assertEmpty($dto->witnesses);
        $this->assertFalse($dto->hasWitnesses);
    }
}
