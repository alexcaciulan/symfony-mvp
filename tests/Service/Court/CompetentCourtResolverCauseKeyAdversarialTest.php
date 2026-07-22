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
 * How fragile the CPC art. 99 grouping is.
 *
 * The rule splits on the cause, so the string that names the cause decides the
 * court. Those strings come out of an extraction that reads each invoice on its
 * own, so the same contract is routinely written differently across a file, and
 * is routinely missing on some invoices. Whenever that happens the positions
 * stop cumulating (alin. 1) and get valued separately (alin. 2), which pushes a
 * tribunal file down to the judecătorie.
 *
 * Material incompetence is of public order and is raised ex officio, so the
 * consequence is the whole petition sent back, not a detail.
 *
 * Fixture: five invoices of 60.000 RON on one contract, 300.000 RON in total,
 * over the 200.000 RON threshold of CPC art. 94 pct. 1 lit. k.
 */
final class CompetentCourtResolverCauseKeyAdversarialTest extends TestCase
{
    private const REFERENCE = '2026-01-01';

    /**
     * The same contract written two ways across the invoices of one file.
     * "Contract nr. 12/2024" and "Contract 12/2024" name one title, so the five
     * positions cumulate to 300.000 and belong to the tribunal.
     */
    public function testTheSameContractWrittenWithAndWithoutNrStillCumulates(): void
    {
        $result = $this->makeResolver()->resolveForItems(
            items: [
                $this->item(1, 60_000.0, 'Contract nr. 12/2024'),
                $this->item(2, 60_000.0, 'Contract nr. 12/2024'),
                $this->item(3, 60_000.0, 'Contract 12/2024'),
                $this->item(4, 60_000.0, 'Contract 12/2024'),
                $this->item(5, 60_000.0, 'Contract nr. 12/2024'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertSame(
            'Tribunalul Cluj',
            $result->court?->getName(),
            'One contract written two ways was split into two causes, dropping a 300.000 RON claim to the judecătorie.'
        );
    }

    /**
     * The extraction found the contract number on three invoices and missed it
     * on two. Nothing about the file changed, so the competence must not change
     * either: either the positions cumulate to the tribunal, or the resolver
     * declines to pick. Quietly filing at the judecătorie is the one outcome
     * that is not acceptable.
     */
    public function testAPartiallyLabelledFileIsNotSilentlyRoutedToTheLowerCourt(): void
    {
        $result = $this->makeResolver()->resolveForItems(
            items: [
                $this->item(1, 60_000.0, 'Contract 12/2024'),
                $this->item(2, 60_000.0, 'Contract 12/2024'),
                $this->item(3, 60_000.0, 'Contract 12/2024'),
                $this->item(4, 60_000.0, null),
                $this->item(5, 60_000.0, null),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNotSame(
            'Judecătoria Cluj-Napoca',
            $result->court?->getName(),
            'Positions with no stated cause were valued apart from the ones naming the contract they belong to.'
        );
    }

    /**
     * Case and whitespace are handled, which is what makes the gap above a gap
     * rather than a deliberate absence of normalization.
     */
    public function testCaseAndWhitespaceVariantsOfOneCauseDoCumulate(): void
    {
        $result = $this->makeResolver()->resolveForItems(
            items: [
                $this->item(1, 60_000.0, 'Contract 12/2024'),
                $this->item(2, 60_000.0, '  CONTRACT   12/2024 '),
                $this->item(3, 60_000.0, 'contract 12/2024'),
                $this->item(4, 60_000.0, 'Contract 12/2024'),
                $this->item(5, 60_000.0, 'CONTRACT 12/2024'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertSame('Tribunalul Cluj', $result->court?->getName());
    }

    /**
     * Genuinely different causes with divergent competence: no court is picked,
     * and the reason says so. This is the behaviour the two tests above are
     * measured against, so it is pinned here.
     */
    public function testDivergentCompetenceAcrossRealCausesRefusesToPick(): void
    {
        $result = $this->makeResolver()->resolveForItems(
            items: [
                $this->item(1, 250_000.0, 'Contract A/2024'),
                $this->item(2, 60_000.0, 'Contract B/2024'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertNull($result->court);
        $this->assertSame('court.resolver.art99_divergent_competence', $result->explanationKey);
        $this->assertSame(310_000.0, $result->claimValue->principal);
    }

    /**
     * Exactly at the threshold the claim stays with the judecătorie: CPC art. 94
     * pct. 1 lit. k covers claims up to and including 200.000 RON.
     */
    public function testTheThresholdItselfBelongsToTheJudecatorie(): void
    {
        $result = $this->makeResolver()->resolveForItems(
            items: [
                $this->item(1, 100_000.0, 'Contract 12/2024'),
                $this->item(2, 100_000.0, 'Contract 12/2024'),
            ],
            referenceDate: new \DateTimeImmutable(self::REFERENCE),
            relationshipType: RelationshipType::COMERCIAL,
            debtorCounty: 'Cluj',
            debtorLocality: 'Cluj-Napoca',
        );

        $this->assertSame('Judecătoria Cluj-Napoca', $result->court?->getName());
        $this->assertSame(200_000.0, $result->claimValue->principal);
    }

    private function item(int $id, float $amount, ?string $cause): ClaimItem
    {
        $item = new ClaimItem();
        $item->setAmount(sprintf('%.2f', $amount));
        $item->setAmountRon(sprintf('%.2f', $amount));
        $item->setCurrency('RON');
        $item->setDueDate(new \DateTimeImmutable('2025-01-31'));
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
