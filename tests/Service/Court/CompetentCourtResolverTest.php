<?php

namespace App\Tests\Service\Court;

use App\Entity\Court;
use App\Entity\InterestRateConfig;
use App\Enum\CourtType;
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

    public function testThresholdAppliesOnTotalClaimValueIncludingAccessories(): void
    {
        // Revizia juridică N1 (2026-05-09): pragul 200k se aplică pe valoarea totală
        // a cererii la data sesizării (CPC art. 98), NU pe principal singular.
        // Principal 190k + dobândă acumulată ~28.5k peste 1 an la BNR 7%
        // (COMERCIAL + PENALIZATOARE → BNR + 8 = 15%) → total ~218.5k → TRIBUNAL.
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

        $this->assertNotNull($result->court, 'Should route to tribunal because total > 200k');
        $this->assertSame('Tribunalul Cluj', $result->court->getName());
        $this->assertSame('court.resolver.matched_tribunal', $result->explanationKey);
        $this->assertSame(190_000.0, $result->claimValue->principal);
        $this->assertGreaterThan(0.0, $result->claimValue->accruedInterest);
        $this->assertGreaterThan(200_000.0, $result->claimValue->total);
    }

    public function testThresholdAppliesOnPrincipalPlusScadentPenaltiesEvenWithoutInterest(): void
    {
        // Revizia juridică N1 (2026-05-09): chiar și fără dobândă, contribuția
        // penalităților contractuale scadente împinge dosarul peste prag.
        // Principal 199k + penalități 2k + dobândă 0 (dueDate==referenceDate) → 201k → TRIBUNAL.
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

        $this->assertNotNull($result->court, 'Pure principal would route to judecătorie; total with scadent penalties routes to tribunal');
        $this->assertSame('Tribunalul Cluj', $result->court->getName());
        $this->assertSame('court.resolver.matched_tribunal', $result->explanationKey);
        $this->assertSame(0.0, $result->claimValue->accruedInterest);
        $this->assertSame(2_000.0, $result->claimValue->scadentPenalties);
        $this->assertSame(201_000.0, $result->claimValue->total);
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

    /** @param list<string> $coveredLocalities */
    private function makeJudecatorie(string $name, string $county, array $coveredLocalities): Court
    {
        $court = new Court();
        $court->setName($name);
        $court->setCounty($county);
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $court->setCoveredLocalities($coveredLocalities);

        return $court;
    }

    private function makeTribunal(string $name, string $county): Court
    {
        $court = new Court();
        $court->setName($name);
        $court->setCounty($county);
        $court->setType(CourtType::TRIBUNAL);
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
                        && $c->getCounty() === $county
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
