<?php

namespace App\Tests\Entity;

use App\Entity\City;
use App\Entity\County;
use App\Enum\UatType;
use PHPUnit\Framework\TestCase;

class CityEntityTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $county = new County();
        $county->setName('Cluj');
        $county->setNormalizedName('cluj');

        $city = new City();
        $city->setCounty($county);
        $city->setName('Cluj-Napoca');
        $city->setNormalizedName('cluj-napoca');
        $city->setType(UatType::MUNICIPIU);

        $this->assertNull($city->getId());
        $this->assertSame($county, $city->getCounty());
        $this->assertSame('Cluj-Napoca', $city->getName());
        $this->assertSame('cluj-napoca', $city->getNormalizedName());
        $this->assertSame(UatType::MUNICIPIU, $city->getType());
    }

    public function testTypeIsNullableByDefault(): void
    {
        $city = new City();

        $this->assertNull($city->getType());
    }

    public function testTypeCanBeResetToNull(): void
    {
        $city = new City();
        $city->setType(UatType::COMUNA);
        $city->setType(null);

        $this->assertNull($city->getType());
    }

    public function testTimestampsSetInConstructor(): void
    {
        $city = new City();

        $this->assertInstanceOf(\DateTimeImmutable::class, $city->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $city->getUpdatedAt());
    }

    public function testToStringReturnsName(): void
    {
        $city = new City();
        $city->setName('Sector 3');

        $this->assertSame('Sector 3', (string) $city);
    }
}
