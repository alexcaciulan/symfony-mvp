<?php

declare(strict_types=1);

namespace App\Tests\Service\Court;

use App\Service\Court\AdministrativeUnitResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the real SIRUTA nomenclature loaded in the test database, since
 * the point of the resolver is agreeing with those exact rows.
 */
class AdministrativeUnitResolverTest extends KernelTestCase
{
    private AdministrativeUnitResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = static::getContainer()->get(AdministrativeUnitResolver::class);
    }

    public function testRewritesAnafDecorationIntoNomenclatureSpelling(): void
    {
        $location = $this->resolver->resolve('MUNICIPIUL BUCUREŞTI', 'Sector 6 Mun. Bucureşti');

        self::assertSame('București', $location->countyName);
        self::assertSame('Sector 6', $location->localityName);
        self::assertTrue($location->countyMatched);
        self::assertTrue($location->localityMatched);
    }

    public function testResolvesAProvincialMunicipality(): void
    {
        $location = $this->resolver->resolve('SIBIU', 'Mun. Mediaş');

        self::assertSame('Sibiu', $location->countyName);
        self::assertSame('Mediaș', $location->localityName);
    }

    /** ANAF names the village; the nomenclature and the town hall know the commune. */
    public function testResolvesAVillageAddressToItsCommune(): void
    {
        $location = $this->resolver->resolve('IAŞI', 'Sat Dancu Com. Holboca');

        self::assertSame('Iași', $location->countyName);
        self::assertSame('Holboca', $location->localityName);
    }

    /**
     * Extracting a Bucharest sector is gated on knowing the county, so without
     * the retry a blank county hides the sector as well.
     */
    public function testInfersBucharestFromASectorWhenTheCountyIsMissing(): void
    {
        $location = $this->resolver->resolve(null, 'Sector 3');

        self::assertSame('București', $location->countyName);
        self::assertSame('Sector 3', $location->localityName);
    }

    public function testKeepsAnUnmatchedValueInsteadOfBlankingIt(): void
    {
        $location = $this->resolver->resolve('Judetul Inexistent', 'Localitate Inexistenta');

        self::assertSame('Judetul Inexistent', $location->countyName);
        self::assertSame('Localitate Inexistenta', $location->localityName);
        self::assertFalse($location->countyMatched);
        self::assertFalse($location->localityMatched);
    }

    public function testToleratesEmptyInput(): void
    {
        $location = $this->resolver->resolve(null, null);

        self::assertNull($location->countyName);
        self::assertNull($location->localityName);
        self::assertFalse($location->countyMatched);
    }
}
