<?php

declare(strict_types=1);

namespace App\Tests\Service\Company;

use App\Service\Company\AnafAddressMapper;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are verbatim v9 payloads captured from the live ANAF endpoint for
 * the companies the lawyer tested with, so a change in our reading of the
 * grammar shows up here rather than in a filed case.
 */
class AnafAddressMapperTest extends TestCase
{
    private AnafAddressMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AnafAddressMapper();
    }

    /**
     * TECHEDGE SOLUTIONS, CUI 49932252. `sdetalii_Adresa` is empty, so the block,
     * staircase, floor and apartment exist only in the flat line.
     */
    public function testRecoversUnitDetailsAbsentFromTheStructuredBlock(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Răsăritului',
            streetNumber: '5',
            flatAddress: 'MUNICIPIUL BUCUREŞTI, SECTOR 6, STR. RĂSĂRITULUI, NR.5, BL.4C, SC.A, ET.3, AP.12',
            postalCode: '061202',
        ));

        self::assertSame('4C', $mapping->parts->block);
        self::assertSame('A', $mapping->parts->staircase);
        self::assertSame('3', $mapping->parts->floor);
        self::assertSame('12', $mapping->parts->apartment);
        self::assertFalse($mapping->fiscalDomicileDiffers);
    }

    /** JURESSA NET, CUI 15663826: floor and apartment only, no postal code at ANAF. */
    public function testRecoversFloorAndApartmentWithoutPostalCode(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Turturelelor',
            streetNumber: '50',
            city: 'Sector 3 Mun. Bucureşti',
            flatAddress: 'MUNICIPIUL BUCUREŞTI, SECTOR 3, STR TURTURELELOR, NR.50, ET.4, AP.1',
            postalCode: null,
        ));

        self::assertSame('4', $mapping->parts->floor);
        self::assertSame('1', $mapping->parts->apartment);
        self::assertNull($mapping->parts->postalCode);
        self::assertNull($mapping->parts->block);
    }

    /**
     * The flat line repeats the county, the locality and the street, all of
     * which live in their own fields. Leaving them in is what printed
     * "Bucureşti" three times on the same line.
     */
    public function testDropsCountyLocalityAndStreetFromTheFreeDetails(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Răsăritului',
            streetNumber: '5',
            flatAddress: 'MUNICIPIUL BUCUREŞTI, SECTOR 6, STR. RĂSĂRITULUI, NR.5, BL.4C',
        ));

        self::assertSame([], $mapping->parts->details);
    }

    /** ONLINE ADVERTISING CONCEPT, CUI 36736564: the detail is in both sources. */
    public function testKeepsAFreeDetailPresentInBothSourcesOnlyOnce(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Azurului',
            streetNumber: '25',
            addressDetails: 'BIROUL NR. 1',
            flatAddress: 'MUNICIPIUL BUCUREŞTI, SECTOR 6, STR. AZURULUI, NR.25, BIROUL NR. 1, ET.3, AP.20',
            fiscalDetails: 'BIROUL NR. 1',
        ));

        self::assertSame(['BIROUL NR. 1'], $mapping->parts->details);
        self::assertSame('3', $mapping->parts->floor);
        self::assertSame('20', $mapping->parts->apartment);
    }

    /**
     * DANTE INTERNATIONAL, CUI 14399840: registered office in Sector 6, fiscal
     * domicile in Sector 2. The flat line describes the latter, so its unit
     * tokens must not be attached to the former.
     */
    public function testSkipsUnitDetailsWhenTheFiscalDomicileDiffers(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Şos. Virtuţii',
            streetNumber: '148',
            city: 'Sector 6 Mun. Bucureşti',
            addressDetails: 'spatiul E47',
            flatAddress: 'MUNICIPIUL BUCUREŞTI, SECTOR 2, STR. GARA HERĂSTRĂU, NR.6, CLADIREA GLOBALWORTH SQUARE, ET.1,2,3,5,8',
            fiscalStreet: 'Str. Gara Herăstrău',
            fiscalStreetNumber: '6',
            fiscalCity: 'Sector 2 Mun. Bucureşti',
            fiscalDetails: 'Cladirea Globalworth Square',
        ));

        self::assertTrue($mapping->fiscalDomicileDiffers);
        self::assertFalse($mapping->parts->hasUnitDetails());
        // The registered office's own detail is still safe to keep.
        self::assertSame(['spatiul E47'], $mapping->parts->details);
    }

    /**
     * ANAF writes a multi-floor tenancy as "ET.1,2,3,5,8". Splitting on commas
     * without re-attaching the continuation would print ", 2, 3, 5, 8" as free
     * text into the somaţie.
     */
    public function testKeepsACommaSeparatedFloorListAsOneValue(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Gara Herăstrău',
            streetNumber: '6',
            city: 'Sector 2 Mun. Bucureşti',
            flatAddress: 'MUNICIPIUL BUCUREŞTI, SECTOR 2, STR. GARA HERĂSTRĂU, NR.6, ET.1,2,3,5,8',
        ));

        self::assertSame('1,2,3,5,8', $mapping->parts->floor);
        self::assertSame([], $mapping->parts->details);
    }

    /**
     * Rural address. The nomenclature (and the town hall) knows the commune, so
     * the locality field resolves to it, but the village is what makes the
     * envelope deliverable and has to survive inside the address.
     */
    public function testKeepsTheVillageWhileTheLocalityResolvesToTheCommune(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Principală',
            streetNumber: '10',
            city: 'Sat Dancu Com. Holboca',
            county: 'IAŞI',
            flatAddress: 'JUD. IAŞI, SAT DANCU, COM. HOLBOCA, STR. PRINCIPALĂ, NR.10',
        ));

        self::assertSame(['SAT DANCU'], $mapping->parts->details);
        self::assertFalse($mapping->parts->hasUnitDetails());
    }

    /**
     * The short label is a literal prefix of the long one, so an alternation
     * ordered short-first matched "BLOC" as "BL" and left "OC" glued to the
     * value. Every one of these printed a mangled address into the somaţie.
     */
    public function testReadsUnitLabelsWrittenInFull(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Test',
            streetNumber: '1',
            flatAddress: 'MUNICIPIUL BUCUREŞTI, SECTOR 6, STR. TEST, NUMARUL 1, BLOC 4C, SCARA A, ETAJ 3, APARTAMENT 12',
        ));

        self::assertSame('4C', $mapping->parts->block);
        self::assertSame('A', $mapping->parts->staircase);
        self::assertSame('3', $mapping->parts->floor);
        self::assertSame('12', $mapping->parts->apartment);
        self::assertSame([], $mapping->parts->details);
    }

    public function testReadsTheAbbreviatedApartmentLabelWithoutADot(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Test',
            streetNumber: '1',
            flatAddress: 'MUNICIPIUL BUCUREŞTI, SECTOR 6, STR. TEST, NR.1, APT 5',
        ));

        self::assertSame('5', $mapping->parts->apartment);
    }

    /** A payload with no flat line at all must not fail or invent details. */
    public function testToleratesAMissingFlatAddress(): void
    {
        $mapping = $this->mapper->map($this->payload(
            street: 'Str. Test',
            streetNumber: '1',
            flatAddress: null,
        ));

        self::assertFalse($mapping->parts->hasUnitDetails());
        self::assertSame('Str. Test', $mapping->parts->street);
    }

    private function payload(
        ?string $street = null,
        ?string $streetNumber = null,
        string $city = 'Sector 6 Mun. Bucureşti',
        string $county = 'MUNICIPIUL BUCUREŞTI',
        ?string $postalCode = null,
        ?string $addressDetails = null,
        ?string $flatAddress = null,
        ?string $fiscalStreet = null,
        ?string $fiscalStreetNumber = null,
        ?string $fiscalCity = null,
        ?string $fiscalDetails = null,
    ): array {
        return [
            'street' => $street,
            'streetNumber' => $streetNumber,
            'city' => $city,
            'county' => $county,
            'postalCode' => $postalCode,
            'addressDetails' => $addressDetails,
            'flatAddress' => $flatAddress,
            'fiscalAddress' => [
                'street' => $fiscalStreet ?? $street,
                'streetNumber' => $fiscalStreetNumber ?? $streetNumber,
                'city' => $fiscalCity ?? $city,
                'county' => $county,
                'postalCode' => $postalCode,
                'addressDetails' => $fiscalDetails ?? $addressDetails,
            ],
        ];
    }
}
