<?php

declare(strict_types=1);

namespace App\Tests\Service\StampDuty;

use App\Entity\City;
use App\Entity\County;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Enum\CourtType;
use App\Enum\PersonType;
use App\Enum\StampDutyTargetStatus;
use App\Repository\CityRepository;
use App\Service\StampDuty\StampDutyUatResolver;
use PHPUnit\Framework\TestCase;

/**
 * The stamp duty is collected by the UAT of the CLAIMANT's registered office
 * (OUG 80/2013 art. 40 alin. 1). Getting this wrong sends the money to the wrong
 * town hall, which courts treat as non-payment, so every unresolved case must be
 * reported as such rather than guessed.
 */
final class StampDutyUatResolverTest extends TestCase
{
    public function testResolvesUatFromCreditorRegisteredOffice(): void
    {
        $county = $this->makeCounty('Cluj', 'cluj');
        $uat = $this->makeCity('Cluj-Napoca', 'cluj-napoca', $county);

        $repository = $this->createStub(CityRepository::class);
        $repository->method('findOneByCountyNameAndNormalizedName')
            ->willReturn($uat);

        $case = $this->makeCase('Cluj', 'Cluj-Napoca');
        $target = (new StampDutyUatResolver($repository))->resolve($case);

        self::assertSame(StampDutyTargetStatus::RESOLVED, $target->status);
        self::assertTrue($target->isResolved());
        self::assertSame('Cluj-Napoca', $target->uatName());
        self::assertSame('Cluj', $target->countyName());
    }

    /** Diacritics and casing must not defeat the lookup: "Târgu Mureș" is one UAT. */
    public function testNormalizesDiacriticsAndCaseBeforeLookup(): void
    {
        $county = $this->makeCounty('Mureș', 'mures');
        $uat = $this->makeCity('Târgu Mureș', 'targu mures', $county);

        $repository = $this->createMock(CityRepository::class);
        $repository->expects(self::once())
            ->method('findOneByCountyNameAndNormalizedName')
            ->with('mures', 'targu mures')
            ->willReturn($uat);

        $case = $this->makeCase('MUREȘ', 'Târgu Mureș');
        $target = (new StampDutyUatResolver($repository))->resolve($case);

        self::assertTrue($target->isResolved());
    }

    /**
     * ANAF returns the village, not the UAT: a company seated in Dancu comes back as
     * "Sat Dancu Com. Holboca", but the stamp duty is collected by the commune of
     * Holboca. Observed on a live lookup, not hypothetical.
     */
    public function testResolvesTheCommuneWhenAnafReportsAVillageAddress(): void
    {
        $county = $this->makeCounty('Iași', 'iasi');
        $commune = $this->makeCity('Holboca', 'holboca', $county);

        $repository = $this->createStub(CityRepository::class);
        $repository->method('findOneByCountyNameAndNormalizedName')
            ->willReturnCallback(static fn (string $c, string $city): ?City => $city === 'holboca' ? $commune : null);

        $case = $this->makeCase('IAŞI', 'Sat Dancu Com. Holboca');
        $target = (new StampDutyUatResolver($repository))->resolve($case);

        self::assertTrue($target->isResolved());
        self::assertSame('Holboca', $target->uatName());
    }

    /** A plain town name must never be mangled by the commune extraction. */
    public function testDoesNotMisreadATownNameAsACommuneAddress(): void
    {
        $county = $this->makeCounty('Cluj', 'cluj');
        $town = $this->makeCity('Cluj-Napoca', 'cluj-napoca', $county);

        $repository = $this->createStub(CityRepository::class);
        $repository->method('findOneByCountyNameAndNormalizedName')->willReturn($town);

        $target = (new StampDutyUatResolver($repository))->resolve($this->makeCase('Cluj', 'Cluj-Napoca'));

        self::assertSame('Cluj-Napoca', $target->uatName());
    }

    public function testReportsLocationMissingWhenCreditorHasNoStructuredOffice(): void
    {
        $repository = $this->createMock(CityRepository::class);
        $repository->expects(self::never())->method('findOneByCountyNameAndNormalizedName');

        $case = $this->makeCase(null, null);
        $target = (new StampDutyUatResolver($repository))->resolve($case);

        self::assertSame(StampDutyTargetStatus::LOCATION_MISSING, $target->status);
        self::assertFalse($target->isResolved());
        self::assertNull($target->uatName());
    }

    /**
     * A locality that matches no Romanian UAT is not silently swapped for the court's
     * seat: it may be a misspelling, or a claimant seated abroad (art. 40 alin. 2).
     * Either way the lawyer decides, and the court name is carried along to help.
     */
    public function testReportsUnmatchedWhenLocalityIsNotARomanianUat(): void
    {
        $repository = $this->createStub(CityRepository::class);
        $repository->method('findOneByCountyNameAndNormalizedName')->willReturn(null);

        $case = $this->makeCase('Wien', 'Wien');
        $target = (new StampDutyUatResolver($repository))->resolve($case);

        self::assertSame(StampDutyTargetStatus::UNMATCHED, $target->status);
        self::assertFalse($target->isResolved());
        self::assertSame('Judecătoria Cluj-Napoca', $target->courtName);
    }

    private function makeCase(?string $county, ?string $locality): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Creditor SRL');
        $creditor->setAddress('Str. Test 1');
        $creditor->setAddressCounty($county);
        $creditor->setAddressLocality($locality);

        $court = new Court();
        $court->setName('Judecătoria Cluj-Napoca');
        $court->setType(CourtType::JUDECATORIE);

        $case = new LegalCase();
        $case->setCreditor($creditor);
        $case->setCourt($court);

        return $case;
    }

    private function makeCounty(string $name, string $normalized): County
    {
        $county = new County();
        $county->setName($name);
        $county->setNormalizedName($normalized);

        return $county;
    }

    private function makeCity(string $name, string $normalized, County $county): City
    {
        $city = new City();
        $city->setName($name);
        $city->setNormalizedName($normalized);
        $city->setCounty($county);

        return $city;
    }
}
