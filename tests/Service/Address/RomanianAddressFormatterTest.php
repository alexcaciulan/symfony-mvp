<?php

declare(strict_types=1);

namespace App\Tests\Service\Address;

use App\Service\Address\AddressParts;
use App\Service\Address\RomanianAddressFormatter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RomanianAddressFormatterTest extends KernelTestCase
{
    private RomanianAddressFormatter $formatter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->formatter = new RomanianAddressFormatter(static::getContainer()->get('translator'));
    }

    /** The shape the lawyer approved on the extraction output, plus the postal code. */
    public function testComposesTheFullAddressInTheApprovedOrder(): void
    {
        $address = $this->formatter->format(new AddressParts(
            street: 'Str. Răsăritului',
            streetNumber: '5',
            block: '4C',
            staircase: 'A',
            floor: '3',
            apartment: '12',
            postalCode: '061202',
        ));

        self::assertSame(
            'Strada Răsăritului, Nr. 5, Bloc 4C, Scara A, Etaj 3, Ap. 12, cod poștal 061202',
            $address,
        );
    }

    public function testOmitsAbsentComponentsWithoutLeavingOrphanCommas(): void
    {
        $address = $this->formatter->format(new AddressParts(
            street: 'Str. Turturelelor',
            streetNumber: '50',
            floor: '4',
            apartment: '1',
        ));

        self::assertSame('Strada Turturelelor, Nr. 50, Etaj 4, Ap. 1', $address);
    }

    /**
     * ANAF spells the artery with a cedilla ("Şos.", U+015E). Keying the
     * expansion map on the ASCII spelling silently left it abbreviated.
     */
    public function testExpandsAnArteryAbbreviationWrittenWithACedilla(): void
    {
        $address = $this->formatter->format(new AddressParts(
            street: 'Şos. Virtuţii',
            streetNumber: '148',
        ));

        self::assertSame('Șoseaua Virtuții, Nr. 148', $address);
    }

    /** ANAF writes the boulevard as "Bld." in the wild, not only "B-dul". */
    public function testExpandsTheBldArteryAbbreviation(): void
    {
        $address = $this->formatter->format(new AddressParts(
            street: 'Bld. 1 Decembrie 1918',
            block: '20',
            apartment: '62',
        ));

        self::assertSame('Bulevardul 1 Decembrie 1918, Bloc 20, Ap. 62', $address);
    }

    public function testLeavesAnUnknownArteryPrefixUntouched(): void
    {
        $address = $this->formatter->format(new AddressParts(street: 'Fdc. Necunoscut', streetNumber: '2'));

        self::assertSame('Fdc. Necunoscut, Nr. 2', $address);
    }

    public function testConvertsCedillaDiacriticsToTheCommaBelowSpelling(): void
    {
        $address = $this->formatter->format(new AddressParts(street: 'Str. Bucureştii Noi'));

        self::assertStringContainsString('Bucureștii', $address);
        self::assertStringNotContainsString("\u{015F}", $address);
    }

    public function testMailingLineAppendsLocalityAndCounty(): void
    {
        $line = $this->formatter->formatMailingLine('Strada Turturelelor, Nr. 50', 'Sector 3', 'București');

        self::assertSame('Strada Turturelelor, Nr. 50, Sector 3, București', $line);
    }

    /** County seats: the county merely repeats the locality (Sibiu, Brăila, Iași). */
    public function testMailingLineDropsACountyThatRepeatsTheLocality(): void
    {
        $line = $this->formatter->formatMailingLine('Strada Alexei Tolstoi, Nr. 8', 'Bacău', 'Bacău');

        self::assertSame('Strada Alexei Tolstoi, Nr. 8, Bacău', $line);
    }

    /**
     * Rows saved before the address was split carry the locality and the county
     * inside the string. The line must not repeat them a second time.
     */
    public function testMailingLineDoesNotRepeatValuesAlreadyInTheAddress(): void
    {
        $line = $this->formatter->formatMailingLine(
            'Str. Rasaritului, nr. 5, Sector 6 Mun. București, MUNICIPIUL BUCUREȘTI',
            'Sector 6',
            'București',
        );

        self::assertSame(1, substr_count($line, 'Sector 6'));
    }

    /**
     * The commune of Traian has "Traian" as its street name too. Matching the
     * street component would drop the UAT off the envelope.
     */
    public function testMailingLineKeepsALocalityThatRepeatsTheStreetName(): void
    {
        $line = $this->formatter->formatMailingLine('Traian, Nr. FN', 'Traian', 'Brăila');

        self::assertSame('Traian, Nr. FN, Traian, Brăila', $line);
    }

    /** Rows written before this fix carry ANAF's decorated spelling inline. */
    public function testMailingLineRecognisesADecoratedLocalityAlreadyInTheAddress(): void
    {
        $line = $this->formatter->formatMailingLine(
            'Str. Azurului, nr. 25, Sector 6 Mun. Bucureşti, MUNICIPIUL BUCUREŞTI, 61192',
            'Sector 6',
            'București',
        );

        self::assertSame('Str. Azurului, nr. 25, Sector 6 Mun. București, MUNICIPIUL BUCUREȘTI, 61192', $line);
    }

    public function testMailingLineToleratesAnEmptyAddress(): void
    {
        self::assertSame('Sector 6, București', $this->formatter->formatMailingLine(null, 'Sector 6', 'București'));
        self::assertSame('', $this->formatter->formatMailingLine(null, null, null));
    }
}
