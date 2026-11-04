<?php

declare(strict_types=1);

namespace App\Tests\Service\Wizard;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Validation\AdmissibilityIssue;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\LegalCase;
use App\Enum\IssueSeverity;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\Case\ClaimItemFactory;
use App\Service\Wizard\WizardFigures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the NBR rates and the courts actually stored: the calculators
 * are final, so their failure paths are reached with the inputs that make
 * them fail, not with doubles.
 */
final class WizardFiguresTest extends KernelTestCase
{
    private WizardFigures $figures;
    private ClaimItemFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->figures = static::getContainer()->get(WizardFigures::class);
        $this->factory = static::getContainer()->get(ClaimItemFactory::class);
    }

    public function testAForeignClaimWithoutARateHasNoPrincipalAndNoFigures(): void
    {
        $claim = $this->claim(currency: 'EUR', invoiceDate: new \DateTimeImmutable('2010-01-15'));

        $conversion = $this->figures->resolveConversion($claim);
        self::assertNull($conversion);
        self::assertNull($this->figures->ronAmount($claim, $conversion));

        $figures = $this->figures->safeComputeForSidebar($claim, $this->debtor(), $conversion);

        self::assertNull($figures['principal']);
        self::assertNull($figures['interest']);
        self::assertNull($figures['court']);
        self::assertSame(0.0, $figures['accessoryTotal']);
        self::assertNotNull($figures['stampDuty']);
    }

    public function testARonClaimRunsOnItsOwnAmount(): void
    {
        $claim = $this->claim();

        self::assertNull($this->figures->resolveConversion($claim));
        self::assertSame(1000.0, $this->figures->ronAmount($claim, null));
    }

    public function testAnUnsupportedRelationLeavesTheInterestEmptyInsteadOfFailing(): void
    {
        $claim = $this->claim(relationshipType: RelationshipType::CIVIL);

        $figures = $this->figures->safeComputeForSidebar($claim, $this->debtor(), null);

        self::assertSame(1000.0, $figures['principal']);
        self::assertNull($figures['interest']);
        self::assertNull($figures['penalty']);
        self::assertSame(0.0, $figures['accessoryTotal']);
        self::assertNull($figures['court']);
    }

    public function testASupportedRelationYieldsInterestAndACourt(): void
    {
        $figures = $this->figures->safeComputeForSidebar($this->claim(), $this->debtor(), null);

        self::assertInstanceOf(InterestResult::class, $figures['interest']);
        self::assertGreaterThan(0.0, $figures['interest']->total);
        self::assertSame($figures['interest']->total, $figures['accessoryTotal']);
        self::assertNotNull($figures['court']);
        self::assertSame([], $figures['claimItems']);
    }

    public function testAnAggregatorFailureYieldsAnEmptyAggregateNotAWrongFigure(): void
    {
        $claim = $this->claim(relationshipType: RelationshipType::CIVIL);
        $skeleton = $this->skeletonWith([$this->row('inv-1', 1000.0, '2024-01-15')]);

        $figures = $this->figures->safeComputeForSidebar($claim, $this->debtor(), null, $skeleton);

        self::assertInstanceOf(AggregatedAccessoryResult::class, $figures['accessoryPerItem']);
        self::assertSame(0.0, $figures['accessoryPerItem']->total);
        self::assertSame(0.0, $figures['accessoryTotal']);
        self::assertNull($figures['interest']);
        self::assertCount(1, $figures['claimItems']);
        self::assertSame(1000.0, $figures['principal']);
    }

    public function testASinglePositionIsBrokenDownLikeTheSingleClaim(): void
    {
        $skeleton = $this->skeletonWith([$this->row('inv-1', 1000.0, '2024-01-15')]);

        $figures = $this->figures->safeComputeForSidebar($this->claim(), $this->debtor(), null, $skeleton);

        self::assertInstanceOf(AggregatedAccessoryResult::class, $figures['accessoryPerItem']);
        self::assertInstanceOf(InterestResult::class, $figures['interest']);
        self::assertSame($figures['accessoryPerItem']->forItem(-1), $figures['interest']);
        self::assertSame(round($figures['interest']->total, 2), $figures['accessoryTotal']);
        self::assertSame(1000.0, $figures['principal']);
    }

    public function testSeveralPositionsCarryNoSingleBreakdown(): void
    {
        $skeleton = $this->skeletonWith([
            $this->row('inv-1', 600.0, '2024-01-15'),
            $this->row('inv-2', 400.0, '2024-03-15'),
        ]);

        $figures = $this->figures->safeComputeForSidebar($this->claim(), $this->debtor(), null, $skeleton);

        self::assertNull($figures['interest']);
        self::assertNull($figures['penalty']);
        self::assertGreaterThan(0.0, $figures['accessoryTotal']);
        self::assertSame(1000.0, $figures['principal']);
        self::assertCount(2, $figures['claimItems']);
    }

    public function testIssuesAreSplitBySeverity(): void
    {
        $error = new AdmissibilityIssue(IssueSeverity::ERROR, 'a', 'x');
        $warning = new AdmissibilityIssue(IssueSeverity::WARNING, 'b', 'y');

        $split = $this->figures->splitIssues([$warning, $error]);

        self::assertSame([$error], $split['errors']);
        self::assertSame([$warning], $split['warnings']);
    }

    public function testTheAccessoryReferenceDateIsTheCutoffWhenEarlierThanToday(): void
    {
        $cutoff = new \DateTimeImmutable('2024-06-30');

        self::assertSame('2024-06-30', $this->figures->accessoryReferenceDate($this->claim(cutoff: $cutoff))->format('Y-m-d'));
        self::assertSame(date('Y-m-d'), $this->figures->accessoryReferenceDate($this->claim())->format('Y-m-d'));
    }

    private function claim(
        string $currency = 'RON',
        ?\DateTimeImmutable $invoiceDate = null,
        RelationshipType $relationshipType = RelationshipType::COMERCIAL,
        ?\DateTimeImmutable $cutoff = null,
    ): Step3ClaimData {
        return new Step3ClaimData(
            amount: 1000.0,
            currency: $currency,
            dueDate: new \DateTimeImmutable('2024-01-15'),
            relationshipType: $relationshipType,
            accessoryCutoffDate: $cutoff,
            invoiceDate: $invoiceDate ?? new \DateTimeImmutable('2023-12-15'),
        );
    }

    private function debtor(): Step2DebtorEntry
    {
        return new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'Debitor SRL',
            cui: 'RO12345678',
            address: 'Str. Exemplu 1',
            addressCounty: 'Cluj',
            addressLocality: 'Cluj-Napoca',
        );
    }

    private function row(string $key, float $amount, string $dueDate): ClaimItemRow
    {
        return new ClaimItemRow(
            dedupKey: $key,
            amount: $amount,
            amountRon: $amount,
            documentNumber: $key,
            dueDate: new \DateTimeImmutable($dueDate),
            confirmed: true,
        );
    }

    /** @param list<ClaimItemRow> $rows */
    private function skeletonWith(array $rows): LegalCase
    {
        $case = new LegalCase();
        $this->factory->materialize($case, $rows);

        return $case;
    }
}
