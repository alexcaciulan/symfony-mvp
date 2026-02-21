<?php

namespace App\Tests\DTO;

use App\DTO\Case\Step2ClaimantData;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\UserType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Step2ClaimantDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    private function createValidDto(): Step2ClaimantData
    {
        $dto = new Step2ClaimantData();
        $dto->type = 'pf';
        $dto->name = 'Ion Popescu';
        $dto->email = 'ion@test.com';

        return $dto;
    }

    public function testValidDtoPassesValidation(): void
    {
        $dto = $this->createValidDto();
        $errors = $this->validator->validate($dto);
        $this->assertCount(0, $errors);
    }

    public function testNameIsRequired(): void
    {
        $dto = $this->createValidDto();
        $dto->name = null;

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testEmailIsRequired(): void
    {
        $dto = $this->createValidDto();
        $dto->email = null;

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testInvalidEmailFails(): void
    {
        $dto = $this->createValidDto();
        $dto->email = 'not-an-email';

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testCnpValidatedOnlyForPfGroup(): void
    {
        $dto = $this->createValidDto();
        $dto->type = 'pf';
        $dto->cnp = '123'; // too short, only 3 chars

        // Without 'pf' group, no CNP validation
        $errors = $this->validator->validate($dto);
        $this->assertCount(0, $errors);

        // With 'pf' group, CNP must be exactly 13 chars
        $errors = $this->validator->validate($dto, null, ['Default', 'pf']);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testCnpPassesWithExactly13Chars(): void
    {
        $dto = $this->createValidDto();
        $dto->cnp = '1234567890123';

        $errors = $this->validator->validate($dto, null, ['Default', 'pf']);
        $this->assertCount(0, $errors);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $dto = $this->createValidDto();
        $dto->city = 'București';
        $dto->county = 'București';

        $array = $dto->toArray();
        $this->assertSame('Ion Popescu', $array['name']);
        $this->assertSame('ion@test.com', $array['email']);
        $this->assertSame('București', $array['city']);
        $this->assertArrayHasKey('cnp', $array);
        $this->assertArrayHasKey('postalCode', $array);
    }

    public function testToLawyerArrayReturnsNullWhenNoLawyer(): void
    {
        $dto = $this->createValidDto();
        $dto->hasLawyer = false;

        $this->assertNull($dto->toLawyerArray());
    }

    public function testToLawyerArrayReturnsDataWhenHasLawyer(): void
    {
        $dto = $this->createValidDto();
        $dto->hasLawyer = true;
        $dto->lawyerName = 'Avocat Test';
        $dto->lawyerPhone = '0721000000';

        $array = $dto->toLawyerArray();
        $this->assertNotNull($array);
        $this->assertSame('Avocat Test', $array['name']);
        $this->assertSame('0721000000', $array['phone']);
    }

    public function testFromLegalCaseLoadsExistingData(): void
    {
        $case = new LegalCase();
        $case->setClaimantType('pj');
        $case->setClaimantData([
            'name' => 'SRL Test',
            'email' => 'srl@test.com',
            'cui' => 'RO12345678',
            'city' => 'Cluj',
        ]);
        $case->setHasLawyer(true);
        $case->setLawyerData(['name' => 'Avocat X', 'barNumber' => 'BUC123']);

        $user = new User();
        $dto = Step2ClaimantData::fromLegalCase($case, $user);

        $this->assertSame('pj', $dto->type);
        $this->assertSame('SRL Test', $dto->name);
        $this->assertSame('srl@test.com', $dto->email);
        $this->assertSame('RO12345678', $dto->cui);
        $this->assertTrue($dto->hasLawyer);
        $this->assertSame('Avocat X', $dto->lawyerName);
    }

    public function testFromLegalCaseFallsBackToUserProfile(): void
    {
        $case = new LegalCase();
        // No claimant data set

        $user = new User();
        $user->setEmail('user@test.com');
        $user->setFirstName('Maria');
        $user->setLastName('Ionescu');
        $user->setPhone('0722333444');
        $user->setType(UserType::PJ);

        $dto = Step2ClaimantData::fromLegalCase($case, $user);

        $this->assertSame('pj', $dto->type);
        $this->assertSame('Maria Ionescu', $dto->name);
        $this->assertSame('user@test.com', $dto->email);
        $this->assertSame('0722333444', $dto->phone);
    }
}
