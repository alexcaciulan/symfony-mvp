<?php

namespace App\Tests\Service\Court;

use App\Entity\Court;
use App\Service\Court\CourtNameIndex;
use PHPUnit\Framework\TestCase;

class CourtNameIndexTest extends TestCase
{
    private function court(string $name): Court
    {
        $court = new Court();
        $court->setName($name);

        return $court;
    }

    public function testFindsCourtByExactName(): void
    {
        $court = $this->court('Judecătoria Sectorului 1 București');
        $index = new CourtNameIndex([$court]);

        $this->assertSame($court, $index->find('Judecătoria Sectorului 1 București'));
    }

    public function testFindsCourtDespiteMissingDiacritics(): void
    {
        $court = $this->court('Judecătoria Sânnicolau Mare');
        $index = new CourtNameIndex([$court]);

        $this->assertSame($court, $index->find('Judecatoria Sannicolau Mare'));
    }

    public function testFindsCourtDespiteCasingAndSpacing(): void
    {
        $court = $this->court('Tribunalul Specializat Cluj');
        $index = new CourtNameIndex([$court]);

        $this->assertSame($court, $index->find('  TRIBUNALUL   specializat Cluj '));
    }

    public function testFindsCourtWrittenWithCedillaVariants(): void
    {
        // Legacy Romanian encodings use cedilla (ş/ţ) where the current standard
        // uses comma-below (ș/ț); both must resolve to the same court.
        $court = $this->court('Judecătoria Bocșa');
        $index = new CourtNameIndex([$court]);

        $this->assertSame($court, $index->find('Judecătoria Bocşa'));
    }

    public function testReturnsNullForUnknownName(): void
    {
        $index = new CourtNameIndex([$this->court('Tribunalul Alba')]);

        $this->assertNull($index->find('Judecătoria Inexistentă'));
        $this->assertFalse($index->isAmbiguous('Judecătoria Inexistentă'));
    }

    public function testReportsAmbiguityWhenTwoCourtsNormalizeAlike(): void
    {
        $index = new CourtNameIndex([
            $this->court('Judecătoria Câmpeni'),
            $this->court('Judecatoria Campeni'),
        ]);

        $this->assertTrue($index->isAmbiguous('Judecatoria CAMPENI'));
        $this->assertNull($index->find('Judecatoria CAMPENI'));
    }

    public function testExactNameWinsOverAmbiguousNormalization(): void
    {
        $withDiacritics = $this->court('Judecătoria Câmpeni');
        $index = new CourtNameIndex([$withDiacritics, $this->court('Judecatoria Campeni')]);

        $this->assertFalse($index->isAmbiguous('Judecătoria Câmpeni'));
        $this->assertSame($withDiacritics, $index->find('Judecătoria Câmpeni'));
    }
}
