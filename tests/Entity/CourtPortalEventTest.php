<?php

namespace App\Tests\Entity;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Enum\PortalEventType;
use PHPUnit\Framework\TestCase;

class CourtPortalEventTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $event = new CourtPortalEvent();

        $this->assertInstanceOf(\DateTimeImmutable::class, $event->getDetectedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->getCreatedAt());
        $this->assertFalse($event->isNotified());
        $this->assertNull($event->getId());
    }

    public function testGettersAndSetters(): void
    {
        $event = new CourtPortalEvent();
        $case = new LegalCase();

        $event->setLegalCase($case);
        $this->assertSame($case, $event->getLegalCase());

        $event->setEventType(PortalEventType::HEARING_SCHEDULED);
        $this->assertSame(PortalEventType::HEARING_SCHEDULED, $event->getEventType());

        $date = new \DateTime('2026-03-15');
        $event->setEventDate($date);
        $this->assertSame($date, $event->getEventDate());

        $event->setDescription('Termen fixat');
        $this->assertSame('Termen fixat', $event->getDescription());

        $event->setSolutie('Admite cererea');
        $this->assertSame('Admite cererea', $event->getSolutie());

        $event->setSolutieSumar('Admis');
        $this->assertSame('Admis', $event->getSolutieSumar());

        $rawData = ['data' => '15.03.2026', 'complet' => 'C1'];
        $event->setRawData($rawData);
        $this->assertSame($rawData, $event->getRawData());

        $event->setNotified(true);
        $this->assertTrue($event->isNotified());
    }

    public function testRawDataJsonReturnsNullWhenNoData(): void
    {
        $event = new CourtPortalEvent();
        $this->assertNull($event->getRawDataJson());
    }

    public function testRawDataJsonReturnsPrettyJson(): void
    {
        $event = new CourtPortalEvent();
        $event->setRawData(['key' => 'value']);

        $json = $event->getRawDataJson();
        $this->assertNotNull($json);
        $this->assertStringContainsString('"key"', $json);
        $this->assertStringContainsString('"value"', $json);
    }

    public function testToString(): void
    {
        $event = new CourtPortalEvent();
        $event->setEventType(PortalEventType::HEARING_SCHEDULED);
        $event->setDescription('Termen 15.03.2026');

        $this->assertStringContainsString('Ședință programată', (string) $event);
        $this->assertStringContainsString('Termen 15.03.2026', (string) $event);
    }
}
