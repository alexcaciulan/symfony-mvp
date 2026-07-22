<?php

declare(strict_types=1);

namespace App\Tests\Service\Court;

use App\Entity\City;
use App\Entity\ClaimItem;
use App\Entity\County;
use App\Entity\Court;
use App\Entity\InterestRateConfig;
use App\Enum\CourtType;
use App\Enum\RelationshipType;
use App\Repository\CourtRepository;
use App\Repository\InterestRateConfigRepository;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Court\CompetentCourtResolver;
use App\Service\Court\LocalityNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * CPC art. 99: claims on different facts or causes are valued one by one
 * (alin. 1); claims on a common title or the same cause are valued together,
 * competence following the head that draws the higher court (alin. 2).
 */
class CompetentCourtResolverArt99Test extends TestCase
{
    private const REFERENCE = '2026-01-01';

    public function testFiveInvoicesOnOneContractCumulateToTheTribunal(): void
    {
        $resolver = $this->makeResolver();

        $result = $resolver->resolveForItems(
            items: $this->items(5, 60_000.0, sameCause: true),
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Tribunalul Cluj', $result->court->getName());
        $this->assertSame(300_000.0, $result->claimValue->principal);
    }

    public function testFiveInvoicesOnDifferentContractsAreValuedSeparately(): void
    {
        // 5 x 60.000 totals 300.000, which on a cumulative reading would route to
        // the tribunal; art. 99 alin. (2) values each at 60.000, so the local
        // court stays competent. Filing at the wrong one draws a plea of material
        // incompetence, raised ex officio.
        $resolver = $this->makeResolver();

        $result = $resolver->resolveForItems(
            items: $this->items(5, 60_000.0, sameCause: false),
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Judecătoria Cluj-Napoca', $result->court->getName());
        $this->assertSame(300_000.0, $result->claimValue->principal);
    }

    public function testPositionsWithNoStatedCauseJoinTheOnlyCauseTheFileNames(): void
    {
        // Three invoices name the contract, two arrive without their header, as a
        // per-document extraction routinely produces. Valuing the unlabelled two
        // apart would drop a 300.000 RON claim to the local court.
        $resolver = $this->makeResolver();
        $items = [
            ...$this->items(3, 60_000.0, sameCause: true),
            ...$this->itemsWithoutCause(2, 60_000.0),
        ];

        $result = $resolver->resolveForItems(
            items: $items,
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Tribunalul Cluj', $result->court->getName());
    }

    public function testUnattributablePositionsAcrossSeveralCausesRefuseToPickACourt(): void
    {
        // Two stated causes plus positions naming none: the grouping cannot be
        // decided, and guessing it decides material competence, which is of
        // public order. The lawyer is asked instead.
        $resolver = $this->makeResolver();
        $items = [
            ...$this->items(2, 60_000.0, sameCause: false),
            ...$this->itemsWithoutCause(1, 60_000.0),
        ];

        $result = $resolver->resolveForItems(
            items: $items,
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertSame('court.resolver.art99_cause_unattributable', $result->explanationKey);
    }

    /** @return list<ClaimItem> */
    private function itemsWithoutCause(int $count, float $amount): array
    {
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $item = new ClaimItem();
            $item->setAmountRon(sprintf('%.2f', $amount));
            $item->setDueDate(new \DateTimeImmutable('2025-01-31'));
            $item->setConfirmedByLawyer(true);
            $items[] = $item;
        }

        return $items;
    }

    public function testDivergentCompetenceAcrossCausesResolvesToNoCourt(): void
    {
        $resolver = $this->makeResolver();

        $items = [
            $this->item(1, 250_000.0, '2025-01-31', 'Contract A'),
            $this->item(2, 10_000.0, '2025-02-28', 'Contract B'),
        ];

        $result = $resolver->resolveForItems(
            items: $items,
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertSame('court.resolver.art99_divergent_competence', $result->explanationKey);
        $this->assertSame(['%causes%' => '2'], $result->explanationParams);
    }

    public function testPositionsWithNoStatedCauseCumulate(): void
    {
        $resolver = $this->makeResolver();

        $result = $resolver->resolveForItems(
            items: [
                $this->item(1, 150_000.0, '2025-01-31', null),
                $this->item(2, 150_000.0, '2025-02-28', null),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotNull($result->court);
        $this->assertSame('Tribunalul Cluj', $result->court->getName());
    }

    public function testASinglePositionResolvesLikeTheScalarPath(): void
    {
        $resolver = $this->makeResolver();
        $reference = new \DateTimeImmutable(self::REFERENCE);

        $fromItems = $resolver->resolveForItems(
            items: [$this->item(1, 10_000.0, '2025-01-31', 'Contract A')],
            referenceDate: $reference,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $fromScalar = $resolver->resolve(
            principal: 10_000.0,
            dueDate: new \DateTimeImmutable('2025-01-31'),
            referenceDate: $reference,
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertSame($fromScalar->court?->getName(), $fromItems->court?->getName());
        $this->assertSame($fromScalar->explanationKey, $fromItems->explanationKey);
        $this->assertSame(
            round($fromScalar->claimValue->accruedInterest, 2),
            round($fromItems->claimValue->accruedInterest, 2),
        );
    }

    public function testAccessoriesStayOutOfTheValuation(): void
    {
        // CPC art. 98 alin. (2): the accessories are shown but never route.
        $resolver = $this->makeResolver();

        $result = $resolver->resolveForItems(
            items: [$this->item(1, 199_000.0, '2024-01-31', 'Contract A')],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertGreaterThan(0.0, $result->claimValue->accruedInterest);
        $this->assertGreaterThan(200_000.0, $result->claimValue->total);
        $this->assertSame('Judecătoria Cluj-Napoca', $result->court?->getName());
    }

    /** @return list<ClaimItem> */
    private function items(int $count, float $amount, bool $sameCause): array
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = $this->item(
                $i,
                $amount,
                '2025-01-31',
                $sameCause ? 'Contract unic 1/2025' : 'Contract ' . $i . '/2025',
            );
        }

        return $items;
    }

    private function item(int $id, float $amount, string $dueDate, ?string $cause): ClaimItem
    {
        $item = new ClaimItem();
        $item->setAmount(sprintf('%.2f', $amount));
        $item->setAmountRon(sprintf('%.2f', $amount));
        $item->setCurrency('RON');
        $item->setDueDate(new \DateTimeImmutable($dueDate));
        $item->setCauseReference($cause);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:' . $id);

        (new \ReflectionProperty(ClaimItem::class, 'id'))->setValue($item, $id);

        return $item;
    }

    private function makeResolver(): CompetentCourtResolver
    {
        $interest = new InterestCalculatorService($this->rateRepository());

        return new CompetentCourtResolver(
            $this->courtRepository(),
            $interest,
            new ClaimInterestAggregator($interest, new ContractualPenaltyCalculator()),
        );
    }

    /** @var array<string, County> */
    private array $countyCache = [];

    private function county(string $name): County
    {
        return $this->countyCache[$name] ??= (new County())
            ->setName($name)
            ->setNormalizedName(LocalityNormalizer::normalize($name) ?? $name);
    }

    private function courtRepository(): CourtRepository
    {
        $tribunal = new Court();
        $tribunal->setName('Tribunalul Cluj');
        $tribunal->setCounty($this->county('Cluj'));
        $tribunal->setType(CourtType::TRIBUNAL);
        $tribunal->setActive(true);

        $city = new City();
        $city->setCounty($this->county('Cluj'));
        $city->setName('Cluj-Napoca');
        $city->setNormalizedName(LocalityNormalizer::normalize('Cluj-Napoca') ?? 'Cluj-Napoca');

        $judecatorie = new Court();
        $judecatorie->setName('Judecătoria Cluj-Napoca');
        $judecatorie->setCounty($this->county('Cluj'));
        $judecatorie->setType(CourtType::JUDECATORIE);
        $judecatorie->setActive(true);
        $judecatorie->addCoveredCity($city);

        $courts = [$tribunal, $judecatorie];

        return new class($courts) extends CourtRepository {
            /** @param list<Court> $courts */
            public function __construct(private array $courts)
            {
                // Only findActiveByTypeAndCounty() is exercised.
            }

            public function findActiveByTypeAndCounty(CourtType $type, string $county): array
            {
                return array_values(array_filter(
                    $this->courts,
                    static fn (Court $c): bool => $c->getType() === $type
                        && $c->getCounty()?->getName() === $county
                        && $c->isActive(),
                ));
            }
        };
    }

    private function rateRepository(): InterestRateConfigRepository
    {
        $config = new InterestRateConfig();
        $config->setValidFrom(new \DateTimeImmutable('2024-01-01'));
        $config->setReferenceRate('6.00');

        return new class([$config]) extends InterestRateConfigRepository {
            /** @param list<InterestRateConfig> $configs */
            public function __construct(private array $configs)
            {
                // Only findAllValidUpTo() is exercised.
            }

            public function findAllValidUpTo(\DateTimeInterface $date): array
            {
                return $this->configs;
            }
        };
    }
}
