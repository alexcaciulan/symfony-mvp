<?php

namespace App\Tests\Entity;

use App\Entity\County;
use PHPUnit\Framework\TestCase;

class CountyEntityTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $county = new County();
        $county->setName('Cluj');
        $county->setNormalizedName('cluj');

        $this->assertNull($county->getId());
        $this->assertSame('Cluj', $county->getName());
        $this->assertSame('cluj', $county->getNormalizedName());
    }

    public function testTimestampsSetInConstructor(): void
    {
        $county = new County();

        $this->assertInstanceOf(\DateTimeImmutable::class, $county->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $county->getUpdatedAt());
    }

    public function testCitiesCollectionStartsEmpty(): void
    {
        $county = new County();

        $this->assertCount(0, $county->getCities());
    }

    public function testToStringReturnsName(): void
    {
        $county = new County();
        $county->setName('București');

        $this->assertSame('București', (string) $county);
    }
}
