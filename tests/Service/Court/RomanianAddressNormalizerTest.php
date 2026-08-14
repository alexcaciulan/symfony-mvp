<?php

declare(strict_types=1);

namespace App\Tests\Service\Court;

use App\Service\Court\RomanianAddressNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The county/locality cases below are the literal payloads the sources return:
 * ANAF answers "MUNICIPIUL BUCUREŞTI" (cedilla Ş, U+015E) for CUI 19180026, and
 * e-Factura writes "SECTOR1". Both must land on the SIRUTA `normalized_name`
 * values ("bucuresti", "sector 1") or the competent-court resolver finds nothing.
 */
final class RomanianAddressNormalizerTest extends TestCase
{
    #[DataProvider('countyProvider')]
    public function testNormalizeCountyStripsAdministrativeQualifiers(?string $input, ?string $expected): void
    {
        self::assertSame($expected, RomanianAddressNormalizer::normalizeCounty($input));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function countyProvider(): iterable
    {
        yield 'anaf bucharest' => ['MUNICIPIUL BUCUREŞTI', 'bucuresti'];
        yield 'comma-below diacritic' => ['Municipiul București', 'bucuresti'];
        yield 'abbreviated municipiu' => ['Mun. București', 'bucuresti'];
        yield 'judetul prefix' => ['Judetul Cluj', 'cluj'];
        yield 'abbreviated judet' => ['Jud. Cluj', 'cluj'];
        yield 'orasul prefix' => ['Orasul Buftea', 'buftea'];
        yield 'bare county untouched' => ['Cluj', 'cluj'];
        yield 'diacritics stripped' => ['Timiș', 'timis'];
        yield 'qualifier-only keeps original' => ['Municipiul', 'municipiul'];
        yield 'null' => [null, null];
        yield 'blank' => ['   ', null];
    }

    #[DataProvider('bucharestLocalityProvider')]
    public function testNormalizeLocalityExtractsBucharestSector(string $input, string $expected): void
    {
        self::assertSame($expected, RomanianAddressNormalizer::normalizeLocality($input, 'bucuresti'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function bucharestLocalityProvider(): iterable
    {
        yield 'anaf form' => ['Sector 1 Mun. Bucureşti', 'sector 1'];
        yield 'e-factura form' => ['SECTOR1', 'sector 1'];
        yield 'articulated form' => ['Sectorul 6', 'sector 6'];
        yield 'already canonical' => ['Sector 3', 'sector 3'];
    }

    /**
     * Sector rewriting must not fire outside Bucharest: "sector" appears in
     * ordinary Romanian addresses (industrial parks, plot descriptions).
     */
    public function testSectorExtractionIsGatedOnBucharest(): void
    {
        self::assertSame(
            'zona industriala sector 2',
            RomanianAddressNormalizer::normalizeLocality('Zona Industriala Sector 2', 'cluj'),
        );
    }

    public function testNormalizeLocalityResolvesVillageToCommune(): void
    {
        self::assertSame(
            'holboca',
            RomanianAddressNormalizer::normalizeLocality('Sat Dancu Com. Holboca', 'iasi'),
        );
    }

    public function testNormalizeLocalityLeavesPlainTownIntact(): void
    {
        self::assertSame('cluj-napoca', RomanianAddressNormalizer::normalizeLocality('Cluj-Napoca', 'cluj'));
    }

    public function testNormalizeLocalityWithoutCountyDoesNotRewriteSector(): void
    {
        self::assertSame('sector1', RomanianAddressNormalizer::normalizeLocality('SECTOR1'));
    }

    /**
     * ANAF prepends an administrative qualifier to every municipality and town
     * ("Mun. Cluj-Napoca", "Municipiul Bacău", "Oraş Huedin"), which must be
     * stripped to match the bare `city.normalized_name`. Verified against live
     * ANAF responses for Banca Transilvania (Cluj), Dedeman (Bacău), Antibiotice
     * (Iaşi).
     */
    #[DataProvider('municipalityPrefixProvider')]
    public function testNormalizeLocalityStripsMunicipalityPrefix(string $input, string $county, string $expected): void
    {
        self::assertSame($expected, RomanianAddressNormalizer::normalizeLocality($input, $county));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function municipalityPrefixProvider(): iterable
    {
        yield 'anaf mun. cluj' => ['Mun. Cluj-Napoca', 'cluj', 'cluj-napoca'];
        yield 'anaf mun. bacau' => ['Mun. Bacău', 'bacau', 'bacau'];
        yield 'anaf mun. iasi cedilla' => ['Mun. Iaşi', 'iasi', 'iasi'];
        yield 'full municipiul' => ['Municipiul Timișoara', 'timis', 'timisoara'];
        yield 'town oras' => ['Oraş Huedin', 'cluj', 'huedin'];
        yield 'town orasul' => ['Orașul Buftea', 'ilfov', 'buftea'];
        // ANAF abbreviates a town as "Orş." with a cedilla, which no other
        // pattern branch consumed: every non-municipality locality failed to
        // match the nomenclature, and with it the court and the stamp-duty UAT.
        yield 'anaf ors. navodari' => ['Orş. Năvodari', 'constanta', 'navodari'];
        yield 'anaf ors. no dot' => ['Ors Buftea', 'ilfov', 'buftea'];
    }

    /**
     * The prefix strip must not eat a town whose name merely starts with those
     * letters: the qualifier is a whole word, so no trailing space means no
     * strip. These are all real UAT rows in the nomenclature.
     */
    #[DataProvider('lookalikeTownProvider')]
    public function testNormalizeLocalityDoesNotManglePrefixLookalikes(string $input, string $expected): void
    {
        self::assertSame($expected, RomanianAddressNormalizer::normalizeLocality($input, 'cluj'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function lookalikeTownProvider(): iterable
    {
        yield 'orsova' => ['Orșova', 'orsova'];
        yield 'orastie' => ['Orăștie', 'orastie'];
        yield 'oravita' => ['Oravița', 'oravita'];
        yield 'oradea' => ['Oradea', 'oradea'];
        yield 'orasu nou' => ['Oraşu Nou', 'orasu nou'];
        yield 'satu mare' => ['Satu Mare', 'satu mare'];
        yield 'comanesti' => ['Comănești', 'comanesti'];
        yield 'munteni' => ['Munteni', 'munteni'];
    }

    /**
     * A municipality prefix on the locality plus a qualified county spelling,
     * as ANAF actually returns them together, must both normalize.
     */
    public function testAnafMunicipalityRoundTrip(): void
    {
        self::assertSame('cluj', RomanianAddressNormalizer::normalizeCounty('CLUJ'));
        self::assertSame('bacau', RomanianAddressNormalizer::normalizeCounty('BACĂU'));
        self::assertSame('timis', RomanianAddressNormalizer::normalizeCounty('Jud. Timiş'));
    }
}
