<?php

namespace App\Tests\Entity;

use App\Entity\InterestRateConfig;
use PHPUnit\Framework\TestCase;

class InterestRateConfigEntityTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $config = new InterestRateConfig();
        $date = new \DateTimeImmutable('2026-01-01');

        $config->setValidFrom($date);
        $config->setReferenceRate('6.50');

        $this->assertSame($date, $config->getValidFrom());
        $this->assertSame('6.50', $config->getReferenceRate());
    }

    public function testTimestampsSet(): void
    {
        $config = new InterestRateConfig();

        $this->assertInstanceOf(\DateTimeImmutable::class, $config->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $config->getUpdatedAt());
    }
}
