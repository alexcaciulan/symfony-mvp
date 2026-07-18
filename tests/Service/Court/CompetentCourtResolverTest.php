<?php

namespace App\Tests\Service\Court;

use App\Entity\City;
use App\Entity\County;
use App\Entity\Court;
use App\Entity\InterestRateConfig;
use App\Enum\CourtType;
use App\Service\Court\LocalityNormalizer;
use App\Enum\RelationshipType;
use App\Repository\CourtRepository;
use App\Repository\InterestRateConfigRepository;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Court\CompetentCourtResolver;
use PHPUnit\Framework\TestCase;

class CompetentCourtResolverTest extends TestCase
{
    public function testJudecatorieUniqueLocalityMatch(): void
    {
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 10_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Judecătoria Cluj-Napoca', $result->court->getName());
        $this->assertSame([], $result->alternatives);
        $this->assertSame('court.resolver.matched_judecatorie', $result->explanationKey);
        $this->assertSame(10_000.0, $result->claimValue->principal);
        $this->assertSame(0.0, $result->claimValue->accruedInterest);
        $this->assertSame(10_000.0, $result->claimValue->total);
    }

    public function testJudecatorieMultipleLocalityMatchesReturnsAlternatives(): void
    {
        $courtA = $this->makeJudecatorie('Judecătoria Test-A', 'TestCounty', ['Shared-Locality']);
        $courtB = $this->makeJudecatorie('Judecătoria Test-B', 'TestCounty', ['Shared-Locality']);
        $courtC = $this->makeJudecatorie('Judecătoria Test-C', 'TestCounty', ['OnlyHere']);

        $resolver = $this->makeResolver(
            courts: [$courtA, $courtB, $courtC],
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 5_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'TestCounty',
            debtorLocality: 'Shared-Locality',
        );

        $this->assertNull($result->court);
        $this->assertCount(2, $result->alternatives);
        $this->assertSame('Judecătoria Test-A', $result->alternatives[0]->getName());
        $this->assertSame('Judecătoria Test-B', $result->alternatives[1]->getName());
        $this->assertSame('court.resolver.locality_ambiguous', $result->explanationKey);
    }

    public function testJudecatorieLocalityNullReturnsAllCandidates(): void
    {
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 10_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: null,
        );

        $this->assertNull($result->court);
        $this->assertCount(5, $result->alternatives);
        $this->assertSame('court.resolver.locality_unmatched_pick_manually', $result->explanationKey);
    }

    public function testBucharestSectorMatch(): void
    {
        $sectors = [];
        for ($i = 1; $i <= 6; $i++) {
            $sectors[] = $this->makeJudecatorie(
                "Judecătoria Sectorului {$i} București",
                'București',
                ["Sector {$i}"],
            );
        }
        $sectors[] = $this->makeTribunal('Tribunalul București', 'București');

        $resolver = $this->makeResolver(
            courts: $sectors,
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 50_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'București',
            debtorLocality: 'Sector 3',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Judecătoria Sectorului 3 București', $result->court->getName());
        $this->assertSame('court.resolver.matched_judecatorie', $result->explanationKey);
    }

    public function testAboveThresholdRoutesToTribunal(): void
    {
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 300_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Tribunalul Cluj', $result->court->getName());
        $this->assertSame(CourtType::TRIBUNAL, $result->court->getType());
        $this->assertSame('court.resolver.matched_tribunal', $result->explanationKey);
        $this->assertSame(300_000.0, $result->claimValue->total);
    }

    public function testSpecializedTribunalCountyRoutesToSpecializedTribunal(): void
    {
        // B2B claim > 200k with debtor seat in Cluj (a specialized-tribunal
        // county per Legea 304/2022 art. 41) routes to the specialized tribunal,
        // not the common county tribunal. Routing is data-driven: the specialized
        // entry exists in the fixtures for Cluj.
        $courts = $this->clujCourts();
        $courts[] = $this->makeSpecializedTribunal('Tribunalul Specializat Cluj', 'Cluj');

        $resolver = $this->makeResolver(
            courts: $courts,
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 300_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Tribunalul Specializat Cluj', $result->court->getName());
        $this->assertSame(CourtType::TRIBUNAL_SPECIALIZAT, $result->court->getType());
        $this->assertSame('court.resolver.matched_tribunal_specializat', $result->explanationKey);
    }

    public function testTwoSpecializedTribunalsInSameCountyReturnsTribunalAmbiguous(): void
    {
        // Defensive guard for invalid master data: two active specialized
        // tribunals in one county → null court + both alternatives for manual pick.
        $courts = [
            $this->makeSpecializedTribunal('Tribunalul Specializat Cluj A', 'Cluj'),
            $this->makeSpecializedTribunal('Tribunalul Specializat Cluj B', 'Cluj'),
        ];

        $resolver = $this->makeResolver(
            courts: $courts,
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 300_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertCount(2, $result->alternatives);
        $this->assertSame('court.resolver.tribunal_ambiguous', $result->explanationKey);
        // The message renders "%county%"; the resolver must supply the param.
        $this->assertSame(['%county%' => 'Cluj'], $result->explanationParams);
    }

    /**
     * When no tribunal exists in the county, the message names the county via a
     * `%county%` placeholder, so the resolver must carry the substitution param.
     * Guards the previously-broken literal-placeholder bug.
     */
    public function testTribunalMissingCarriesCountyParam(): void
    {
        $resolver = $this->makeResolver(
            courts: [$this->makeJudecatorie('Judecătoria Cluj-Napoca', 'Cluj', ['Cluj-Napoca'])],
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 300_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertSame('court.resolver.tribunal_missing', $result->explanationKey);
        $this->assertSame(['%county%' => 'Cluj'], $result->explanationParams);
    }

    public function testNoJudecatorieInCountyCarriesCountyParam(): void
    {
        $resolver = $this->makeResolver(
            courts: [$this->makeTribunal('Tribunalul Cluj', 'Cluj')],
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 5_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertSame('court.resolver.no_judecatorie_in_county', $result->explanationKey);
        $this->assertSame(['%county%' => 'Cluj'], $result->explanationParams);
    }

    public function testTribunalCountyWithoutSpecializedFallsBackToCommonTribunal(): void
    {
        // A county without a specialized tribunal (Iași) routes a > 200k claim to
        // the common county tribunal. Guards the data-driven fallback branch.
        $resolver = $this->makeResolver(
            courts: [$this->makeTribunal('Tribunalul Iași', 'Iași')],
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 300_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Iași',
            debtorLocality: 'Iași',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Tribunalul Iași', $result->court->getName());
        $this->assertSame(CourtType::TRIBUNAL, $result->court->getType());
        $this->assertSame('court.resolver.matched_tribunal', $result->explanationKey);
    }

    public function testZeroPrincipalReturnsInvalidAmountZero(): void
    {
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 0.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertSame([], $result->alternatives);
        $this->assertSame('court.resolver.invalid_amount_zero', $result->explanationKey);
    }

    public function testThresholdIgnoresAccruedInterestAndAppliesOnPrincipalOnly(): void
    {
        // CPC art. 98 alin. (2): accessories (interest, penalties) are excluded from
        // the competence valuation "regardless of the due date". Principal 190k stays
        // under 200k → JUDECĂTORIE, even though accrued interest (~28.5k over 1 year
        // at BNR 7% + 8 = 15%) pushes the displayed total over 200k. The interest is
        // still computed and surfaced in the breakdown, just not used for routing.
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '7.00')],
        );

        $result = $resolver->resolve(
            principal: 190_000.0,
            dueDate: new \DateTimeImmutable('2024-01-01'),
            referenceDate: new \DateTimeImmutable('2025-01-01'),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court, 'Principal 190k < 200k → judecătorie, interest excluded (art. 98 alin. 2)');
        $this->assertSame('Judecătoria Cluj-Napoca', $result->court->getName());
        $this->assertSame('court.resolver.matched_judecatorie', $result->explanationKey);
        $this->assertSame(190_000.0, $result->claimValue->principal);
        $this->assertGreaterThan(0.0, $result->claimValue->accruedInterest, 'Interest is computed and shown in the breakdown');
        $this->assertGreaterThan(200_000.0, $result->claimValue->total, 'Total exceeds 200k but does not drive competence');
    }

    public function testThresholdIgnoresScadentPenaltiesAndAppliesOnPrincipalOnly(): void
    {
        // CPC art. 98 alin. (2): contractual penalties are accessories excluded from
        // the competence valuation. Principal 199k + penalties 2k = 201k total, but
        // competence follows the principal alone (199k < 200k) → JUDECĂTORIE.
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 199_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
            scadentPenalties: 2_000.0,
        );

        $this->assertNotNull($result->court, 'Principal 199k < 200k → judecătorie, penalties excluded (art. 98 alin. 2)');
        $this->assertSame('Judecătoria Cluj-Napoca', $result->court->getName());
        $this->assertSame('court.resolver.matched_judecatorie', $result->explanationKey);
        $this->assertSame(0.0, $result->claimValue->accruedInterest);
        $this->assertSame(2_000.0, $result->claimValue->scadentPenalties);
        $this->assertSame(201_000.0, $result->claimValue->total, 'Total with penalties exceeds 200k but does not drive competence');
    }

    public function testPrincipalJustAboveThresholdRoutesToTribunalRegardlessOfAccessories(): void
    {
        // The mirror of the exactly-200k boundary test: the smallest principal above
        // the threshold (200.000,01) already routes to the tribunal, with zero
        // accessories. The strict ">" comparison flips precisely at this point.
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 200_000.01,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court, 'Principal 200.000,01 > 200.000 → tribunal');
        $this->assertSame('Tribunalul Cluj', $result->court->getName());
        $this->assertSame('court.resolver.matched_tribunal', $result->explanationKey);
        $this->assertSame(200_000.01, $result->claimValue->principal);
        $this->assertSame(0.0, $result->claimValue->accruedInterest);
        $this->assertSame(200_000.01, $result->claimValue->total, 'Breakdown total equals principal with zero accessories');
    }

    public function testPrincipalExactlyAtThresholdRoutesToJudecatorie(): void
    {
        // Boundary: the threshold is strict ">" (CPC art. 94 pct. 1 lit. k — "până la
        // 200.000 RON inclusiv"), so a principal of exactly 200.000 stays at judecătorie.
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 200_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court, 'Principal exactly 200k ≤ 200k → judecătorie');
        $this->assertSame('Judecătoria Cluj-Napoca', $result->court->getName());
        $this->assertSame('court.resolver.matched_judecatorie', $result->explanationKey);
        $this->assertSame(200_000.0, $result->claimValue->principal);
    }

    public function testComputeLegalInterestFalseSuppressesInterestForContractualPenalty(): void
    {
        // A claim with a contractual penalty must NOT also accrue legal interest in
        // the displayed total (double accessory): the penalty clause stands in lieu
        // of legal interest. Competence here follows the principal alone (190k < 200k
        // → judecătorie, per art. 98 alin. 2) independently of the suppression; the
        // flag only governs whether interest shows up in the breakdown.
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '7.00')],
        );

        $result = $resolver->resolve(
            principal: 190_000.0,
            dueDate: new \DateTimeImmutable('2024-01-01'),
            referenceDate: new \DateTimeImmutable('2025-01-01'),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
            scadentPenalties: 2_000.0,
            computeLegalInterest: false,
        );

        $this->assertNotNull($result->court, 'Principal 190k < 200k → judecătorie');
        $this->assertSame('Judecătoria Cluj-Napoca', $result->court->getName());
        $this->assertSame('court.resolver.matched_judecatorie', $result->explanationKey);
        $this->assertSame(0.0, $result->claimValue->accruedInterest, 'Legal interest must be suppressed in the breakdown');
        $this->assertSame(2_000.0, $result->claimValue->scadentPenalties);
        $this->assertSame(192_000.0, $result->claimValue->total);
    }

    public function testNegativePrincipalReturnsInvalidAmountNegative(): void
    {
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: -100.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertSame([], $result->alternatives);
        $this->assertSame('court.resolver.invalid_amount_negative', $result->explanationKey);
    }

    public function testNullCountyReturnsCountyUnknown(): void
    {
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $sameDate = new \DateTimeImmutable('2024-06-01');
        $result = $resolver->resolve(
            principal: 5_000.0,
            dueDate: $sameDate,
            referenceDate: $sameDate,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: null,
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertSame([], $result->alternatives);
        $this->assertSame('court.resolver.county_unknown', $result->explanationKey);
    }

    public function testCivilRelationshipPropagatesDomainException(): void
    {
        // Pas 2.1 revizie C3: CIVIL aruncă DomainException (B2B-only MVP).
        // Resolverul nu suprimă — propagă fail-fast.
        $resolver = $this->makeResolver(
            courts: $this->clujCourts(),
            rates: [$this->makeRateConfig('2024-01-01', '6.00')],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/B2B exclusiv/');

        $resolver->resolve(
            principal: 10_000.0,
            dueDate: new \DateTimeImmutable('2024-01-01'),
            referenceDate: new \DateTimeImmutable('2024-06-01'),
            relationshipType: RelationshipType::CIVIL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );
    }

    /**
     * @param list<Court> $courts
     * @param list<InterestRateConfig> $rates
     */
    private function makeResolver(array $courts, array $rates): CompetentCourtResolver
    {
        return new CompetentCourtResolver(
            $this->makeCourtRepository($courts),
            new InterestCalculatorService($this->makeRateRepository($rates)),
        );
    }

    /** @return list<Court> */
    private function clujCourts(): array
    {
        return [
            $this->makeTribunal('Tribunalul Cluj', 'Cluj'),
            $this->makeJudecatorie('Judecătoria Cluj-Napoca', 'Cluj', ['Cluj-Napoca']),
            $this->makeJudecatorie('Judecătoria Dej', 'Cluj', ['Dej']),
            $this->makeJudecatorie('Judecătoria Gherla', 'Cluj', ['Gherla']),
            $this->makeJudecatorie('Judecătoria Huedin', 'Cluj', ['Huedin']),
            $this->makeJudecatorie('Judecătoria Turda', 'Cluj', ['Turda']),
        ];
    }

    /** @var array<string, County> */
    private array $countyCache = [];

    private function county(string $name): County
    {
        return $this->countyCache[$name] ??= (new County())
            ->setName($name)
            ->setNormalizedName(LocalityNormalizer::normalize($name) ?? $name);
    }

    private function city(string $county, string $name): City
    {
        $city = new City();
        $city->setCounty($this->county($county));
        $city->setName($name);
        $city->setNormalizedName(LocalityNormalizer::normalize($name) ?? $name);

        return $city;
    }

    /** @param list<string> $coveredLocalities */
    private function makeJudecatorie(string $name, string $county, array $coveredLocalities): Court
    {
        $court = new Court();
        $court->setName($name);
        $court->setCounty($this->county($county));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        foreach ($coveredLocalities as $cityName) {
            $court->addCoveredCity($this->city($county, $cityName));
        }

        return $court;
    }

    private function makeTribunal(string $name, string $county): Court
    {
        $court = new Court();
        $court->setName($name);
        $court->setCounty($this->county($county));
        $court->setType(CourtType::TRIBUNAL);
        $court->setActive(true);

        return $court;
    }

    private function makeSpecializedTribunal(string $name, string $county): Court
    {
        $court = new Court();
        $court->setName($name);
        $court->setCounty($this->county($county));
        $court->setType(CourtType::TRIBUNAL_SPECIALIZAT);
        $court->setActive(true);

        return $court;
    }

    private function makeRateConfig(string $validFrom, string $rate): InterestRateConfig
    {
        $config = new InterestRateConfig();
        $config->setValidFrom(new \DateTimeImmutable($validFrom));
        $config->setReferenceRate($rate);

        return $config;
    }

    /** @param list<Court> $courts */
    private function makeCourtRepository(array $courts): CourtRepository
    {
        return new class($courts) extends CourtRepository {
            /** @param list<Court> $courts */
            public function __construct(private array $courts)
            {
                // intentionally skip parent constructor — only findActiveByTypeAndCounty() is exercised
            }

            public function findActiveByTypeAndCounty(CourtType $type, string $county): array
            {
                return array_values(array_filter(
                    $this->courts,
                    fn(Court $c) => $c->getType() === $type
                        && $c->getCounty()->getName() === $county
                        && $c->isActive(),
                ));
            }
        };
    }

    /** @param list<InterestRateConfig> $configs */
    private function makeRateRepository(array $configs): InterestRateConfigRepository
    {
        return new class($configs) extends InterestRateConfigRepository {
            /** @param list<InterestRateConfig> $configs */
            public function __construct(private array $configs)
            {
                // intentionally skip parent constructor — only findAllValidUpTo() is exercised
            }

            public function findAllValidUpTo(\DateTimeInterface $date): array
            {
                $matching = array_filter(
                    $this->configs,
                    fn(InterestRateConfig $c) => $c->getValidFrom() <= $date,
                );
                usort(
                    $matching,
                    fn(InterestRateConfig $a, InterestRateConfig $b) => $a->getValidFrom() <=> $b->getValidFrom(),
                );

                return array_values($matching);
            }
        };
    }
}
