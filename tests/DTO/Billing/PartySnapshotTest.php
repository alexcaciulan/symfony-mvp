<?php

declare(strict_types=1);

namespace App\Tests\DTO\Billing;

use App\DTO\Billing\PartySnapshot;
use App\Entity\User;
use App\Enum\UserType;
use PHPUnit\Framework\TestCase;

class PartySnapshotTest extends TestCase
{
    public function testToArrayFromArrayRoundTrip(): void
    {
        $snapshot = new PartySnapshot(
            name: 'Cabinet Popescu',
            cui: 'RO12345678',
            personalId: null,
            onrcNumber: 'J40/123/2020',
            address: 'Str. Test nr. 1, București',
            iban: 'RO00BANK0000',
            email: 'a@b.ro',
            phone: '0700000000',
        );

        $rebuilt = PartySnapshot::fromArray($snapshot->toArray());

        $this->assertEquals($snapshot, $rebuilt);
        $this->assertSame('Cabinet Popescu', $rebuilt->name);
        $this->assertSame('RO12345678', $rebuilt->cui);
    }

    public function testFromArrayToleratesMissingKeys(): void
    {
        $snapshot = PartySnapshot::fromArray(['name' => 'X']);

        $this->assertSame('X', $snapshot->name);
        $this->assertNull($snapshot->cui);
        $this->assertSame('', $snapshot->address);
    }

    public function testFromUserCompanyUsesCompanyNameAndComposesAddress(): void
    {
        $user = new User();
        $user->setType(UserType::PJ);
        $user->setCompanyName('SCA Popescu');
        $user->setCui('RO99');
        $user->setEmail('office@sca.ro');
        $user->setStreet('Str. Victoriei');
        $user->setStreetNumber('10');
        $user->setApartment('5');
        $user->setCity('București');
        $user->setCounty('Sector 1');
        $user->setPostalCode('010101');

        $snapshot = PartySnapshot::fromUser($user);

        $this->assertSame('SCA Popescu', $snapshot->name);
        $this->assertSame('RO99', $snapshot->cui);
        $this->assertStringContainsString('Str. Victoriei', $snapshot->address);
        $this->assertStringContainsString('nr. 10', $snapshot->address);
        $this->assertStringContainsString('ap. 5', $snapshot->address);
        $this->assertStringContainsString('București', $snapshot->address);
    }

    public function testFromUserNaturalPersonUsesFullName(): void
    {
        $user = new User();
        $user->setType(UserType::PF);
        $user->setFirstName('Ion');
        $user->setLastName('Ionescu');
        $user->setCnp('1900101000000');
        $user->setEmail('ion@test.ro');

        $snapshot = PartySnapshot::fromUser($user);

        $this->assertSame('Ion Ionescu', $snapshot->name);
        $this->assertSame('1900101000000', $snapshot->personalId);
        $this->assertNull($snapshot->cui);
    }
}
