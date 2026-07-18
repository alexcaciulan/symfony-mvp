<?php

declare(strict_types=1);

namespace App\Tests\Service\StampDuty;

use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Enum\PersonType;
use App\Enum\StampDutyTargetStatus;
use App\Repository\CityRepository;
use App\Service\StampDuty\StampDutyUatResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Data-driven guard for the stamp-duty UAT resolution across many counties,
 * against the full SIRUTA nomenclature (3186 UAT rows) in the test database.
 *
 * The county/locality literals are how ANAF actually decorates a registered
 * office: the county comes bare-uppercase with cedilla diacritics ("BACĂU",
 * "IAŞI"), and every municipality/town carries a leading qualifier ("Mun.
 * Cluj-Napoca", "Oraş Huedin"). A claimant seated in any of these must reach
 * the town hall that collects the duty (OUG 80/2013 art. 40 alin. 1); before
 * the address normalizer stripped the locality qualifier, only bare AI spellings
 * resolved and every ANAF-synced office outside a plain-named town failed.
 */
final class StampDutyDiverseCountyTest extends KernelTestCase
{
    private StampDutyUatResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = new StampDutyUatResolver(static::getContainer()->get(CityRepository::class));
    }

    /**
     * ANAF-decorated offices across counties must resolve to a real UAT. The
     * expected UAT is asserted via the bare control spelling resolving to the
     * same row, which sidesteps display-name diacritic drift in the seed.
     */
    #[DataProvider('anafOfficesAcrossCounties')]
    public function testAnafDecoratedOfficeResolvesToTheSameUatAsItsBareForm(
        string $anafCounty,
        string $anafLocality,
        string $bareCounty,
        string $bareLocality,
    ): void {
        $decorated = $this->resolver->resolve($this->caseFor($anafCounty, $anafLocality));
        $bare = $this->resolver->resolve($this->caseFor($bareCounty, $bareLocality));

        self::assertSame(StampDutyTargetStatus::RESOLVED, $bare->status, "bare control must resolve: $bareCounty / $bareLocality");
        self::assertSame(
            StampDutyTargetStatus::RESOLVED,
            $decorated->status,
            "ANAF office must resolve: $anafCounty / $anafLocality",
        );
        self::assertSame(
            $bare->uatName(),
            $decorated->uatName(),
            "ANAF and bare spellings must land on the same UAT ($anafLocality)",
        );
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function anafOfficesAcrossCounties(): iterable
    {
        // Municipalities: ANAF "Mun. X" + cedilla county vs bare AI spelling.
        yield 'Cluj-Napoca'    => ['CLUJ', 'Mun. Cluj-Napoca', 'Cluj', 'Cluj-Napoca'];
        yield 'Bacau'          => ['BACĂU', 'Mun. Bacău', 'Bacau', 'Bacau'];
        yield 'Iasi'           => ['IAŞI', 'Mun. Iaşi', 'Iasi', 'Iasi'];
        yield 'Timisoara'      => ['TIMIŞ', 'Municipiul Timişoara', 'Timis', 'Timisoara'];
        yield 'Constanta'      => ['CONSTANŢA', 'Mun. Constanţa', 'Constanta', 'Constanta'];
        yield 'Craiova'        => ['DOLJ', 'Mun. Craiova', 'Dolj', 'Craiova'];
        yield 'Targu Mures'    => ['MUREŞ', 'Mun. Târgu Mureş', 'Mures', 'Targu Mures'];
        yield 'Ramnicu Valcea' => ['VÂLCEA', 'Mun. Râmnicu Vâlcea', 'Valcea', 'Ramnicu Valcea'];
        // Town (oraș) with the town qualifier.
        yield 'Huedin oras'    => ['CLUJ', 'Oraş Huedin', 'Cluj', 'Huedin'];
        yield 'Buftea orasul'  => ['ILFOV', 'Orașul Buftea', 'Ilfov', 'Buftea'];
        // Town whose name starts with a qualifier lookalike (must not be mangled).
        yield 'Satu Mare'      => ['SATU MARE', 'Mun. Satu Mare', 'Satu Mare', 'Satu Mare'];
        // Rural: ANAF village address resolves to its commune.
        yield 'village->commune' => ['IAŞI', 'Sat Dancu Com. Holboca', 'Iasi', 'Holboca'];
    }

    /**
     * A Bucharest sector office, across the three spellings, resolves to the
     * sector UAT. Kept here so the diverse-county sweep also pins the capital.
     */
    #[DataProvider('bucharestSpellings')]
    public function testBucharestSectorOfficeResolves(string $county, string $locality): void
    {
        $target = $this->resolver->resolve($this->caseFor($county, $locality));

        self::assertSame(StampDutyTargetStatus::RESOLVED, $target->status);
        self::assertSame('București', $target->countyName());
        self::assertSame('Sector 3', $target->uatName());
    }

    /** @return iterable<string, array{string, string}> */
    public static function bucharestSpellings(): iterable
    {
        yield 'anaf'      => ['MUNICIPIUL BUCUREŞTI', 'Sector 3 Mun. Bucureşti'];
        yield 'ai'        => ['București', 'Sector 3'];
        yield 'e-factura' => ['BUCUREŞTI', 'SECTOR3'];
    }

    /**
     * A foreign or unrecognized office is reported as unmatched, never silently
     * swapped: the claimant may be seated abroad (art. 40 alin. 2).
     */
    public function testForeignOfficeIsReportedUnmatched(): void
    {
        $target = $this->resolver->resolve($this->caseFor('Wien', 'Wien'));

        self::assertSame(StampDutyTargetStatus::UNMATCHED, $target->status);
    }

    private function caseFor(?string $county, ?string $locality): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Creditor SRL');
        $creditor->setAddress('Str. Test 1');
        $creditor->setAddressCounty($county);
        $creditor->setAddressLocality($locality);

        $case = new LegalCase();
        $case->setCreditor($creditor);

        return $case;
    }
}
