<?php

declare(strict_types=1);

namespace App\Tests\Service\Court;

use App\Enum\CourtType;
use App\Repository\CourtRepository;
use App\Service\Court\RomanianAddressNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end guard on the Bucharest address vocabulary, against the real SIRUTA
 * nomenclature rather than fixtures.
 *
 * The literals below are what the sources actually return for a Sector 1
 * debtor: ANAF answers "MUNICIPIUL BUCUREŞTI" / "Sector 1 Mun. Bucureşti" (live
 * response for CUI 19180026), and e-Factura encodes the county as the ISO
 * 3166-2 code "RO-B" with the city as "SECTOR1". Matching those against the
 * nomenclature's "București" / "Sector 1" is what decides whether the lawyer
 * gets a court or "Instanță nedeterminată".
 */
final class BucharestCourtResolutionTest extends KernelTestCase
{
    private CourtRepository $courts;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->courts = static::getContainer()->get(CourtRepository::class);
    }

    /**
     * Every Bucharest county spelling must reach the six sector courts. Before
     * the address normalizer this returned 0 rows for the ANAF spelling, which
     * the resolver reported as "no judecatorie in county".
     */
    #[DataProvider('bucharestCountySpellings')]
    public function testBucharestCountySpellingsAllFindTheSectorCourts(string $county): void
    {
        $found = $this->courts->findActiveByTypeAndCounty(CourtType::JUDECATORIE, $county);

        self::assertCount(6, $found, sprintf('Expected the 6 sector courts for county "%s"', $county));
    }

    /** @return iterable<string, array{string}> */
    public static function bucharestCountySpellings(): iterable
    {
        yield 'anaf cedilla spelling' => ['MUNICIPIUL BUCUREŞTI'];
        yield 'comma-below spelling' => ['Municipiul București'];
        yield 'abbreviated' => ['Mun. București'];
        yield 'bare' => ['București'];
        yield 'no diacritics' => ['Bucuresti'];
    }

    /**
     * The locality spellings must all land on the `city.normalized_name` the
     * court coverage is keyed by, so the resolver can pick a single sector court.
     */
    #[DataProvider('sectorSpellings')]
    public function testSectorSpellingsNormalizeToTheNomenclatureForm(string $locality, string $expected): void
    {
        $normalized = RomanianAddressNormalizer::normalizeLocality(
            $locality,
            RomanianAddressNormalizer::normalizeCounty('MUNICIPIUL BUCUREŞTI'),
        );

        self::assertSame($expected, $normalized);

        $matches = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM city c JOIN county co ON c.county_id = co.id
             WHERE co.normalized_name = :county AND c.normalized_name = :city',
            ['county' => 'bucuresti', 'city' => $normalized],
        );

        self::assertSame(1, (int) $matches, sprintf('"%s" must match exactly one city row', $locality));
    }

    /** @return iterable<string, array{string, string}> */
    public static function sectorSpellings(): iterable
    {
        yield 'anaf spelling' => ['Sector 1 Mun. Bucureşti', 'sector 1'];
        yield 'e-factura spelling' => ['SECTOR1', 'sector 1'];
        yield 'articulated' => ['Sectorul 6', 'sector 6'];
        yield 'canonical' => ['Sector 3', 'sector 3'];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
