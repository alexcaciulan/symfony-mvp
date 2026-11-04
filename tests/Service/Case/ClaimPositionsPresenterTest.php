<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\InterestPeriod;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyPeriod;
use App\DTO\Calculation\PenaltyResult;
use App\DTO\Case\ClaimPositionRow;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Enum\ClaimExclusionReason;
use App\Enum\PenaltyType;
use App\Service\Case\ClaimPositionsPresenter;
use PHPUnit\Framework\TestCase;

final class ClaimPositionsPresenterTest extends TestCase
{
    private const REFERENCE = '2026-10-29';

    private int $nextId = 100;

    public function testCountingPositionsComeFirstByDueDateAndTheSetAsideOnesLast(): void
    {
        $case = new LegalCase();
        $this->item($case, 'F-LATE', '1000.00', '2026-08-18');
        $excluded = $this->item($case, 'F-EXCL', '500.00', '2026-01-10');
        $excluded->setExcludedByLawyer(true);
        $excluded->setExclusionReason(ClaimExclusionReason::cases()[0]);
        $this->item($case, 'F-OLD', '2000.00', '2026-03-05');
        $pending = $this->item($case, 'F-USD', '1200.00', '2026-02-01', 'USD', null);
        $pending->setNeedsManualFx(true);

        $view = (new ClaimPositionsPresenter())->present($case, null, new \DateTimeImmutable(self::REFERENCE));

        self::assertSame(['F-OLD', 'F-LATE', 'F-EXCL', 'F-USD'], array_map(static fn (ClaimPositionRow $row): ?string => $row->item->getDocumentNumber(), $view->rows));
        self::assertSame(2, $view->countingCount);
        self::assertSame(1, $view->excludedCount);
        self::assertSame(1, $view->attentionCount);
        self::assertSame(4, $view->totalCount());
        self::assertTrue($view->hasForeignCurrency);
        self::assertFalse($view->showFilters());
    }

    public function testStatesFollowTheModel(): void
    {
        $case = new LegalCase();
        $this->item($case, 'F-1', '1000.00', '2026-08-18');
        $excluded = $this->item($case, 'F-2', '500.00', '2026-08-18');
        $excluded->setExcludedByLawyer(true);
        $fx = $this->item($case, 'F-3', '100.00', '2026-08-18', 'USD', null);
        $fx->setNeedsManualFx(true);
        $unconfirmed = $this->item($case, 'F-4', '100.00', '2026-08-18');
        $unconfirmed->setConfirmedByLawyer(false);

        $rows = $this->byNumber((new ClaimPositionsPresenter())->present($case, null, new \DateTimeImmutable(self::REFERENCE)));

        self::assertSame(ClaimPositionRow::STATE_COUNTING, $rows['F-1']->state);
        self::assertSame('in', $rows['F-1']->filterState());
        self::assertSame(ClaimPositionRow::STATE_EXCLUDED, $rows['F-2']->state);
        self::assertSame('excluded', $rows['F-2']->filterState());
        self::assertSame(ClaimPositionRow::STATE_PENDING, $rows['F-3']->state);
        self::assertTrue($rows['F-3']->fxMissing);
        self::assertNull($rows['F-3']->restRon);
        self::assertSame('attention', $rows['F-3']->filterState());
        self::assertSame(ClaimPositionRow::STATE_PENDING, $rows['F-4']->state);
        self::assertFalse($rows['F-4']->fxMissing);
        self::assertTrue($rows['F-1']->lastCounting, 'The only counting position cannot be excluded.');
        self::assertSame(72, $rows['F-1']->daysLate);
        self::assertNull($rows['F-2']->daysLate, 'Delay is shown only on counting positions.');
    }

    public function testLastCountingIsFalseWithTwoCountingPositions(): void
    {
        $case = new LegalCase();
        $this->item($case, 'F-1', '1000.00', '2026-08-18');
        $this->item($case, 'F-2', '1000.00', '2026-08-19');

        $view = (new ClaimPositionsPresenter())->present($case, null, new \DateTimeImmutable(self::REFERENCE));

        foreach ($view->rows as $row) {
            self::assertFalse($row->lastCounting);
        }
    }

    public function testAddedPositionsAreFlagged(): void
    {
        $case = new LegalCase();
        $old = $this->item($case, 'F-1', '1000.00', '2026-08-18');
        $new = $this->item($case, 'F-2', '1000.00', '2026-08-19');

        $rows = $this->byNumber((new ClaimPositionsPresenter())->present($case, null, new \DateTimeImmutable(self::REFERENCE), [$new->getId()]));

        self::assertFalse($rows['F-1']->added);
        self::assertTrue($rows['F-2']->added);
    }

    public function testPaidPartIsTakenInLeiInTheSameProportion(): void
    {
        $case = new LegalCase();
        $eur = $this->item($case, 'F-EUR', '2500.00', '2026-06-27', 'EUR', '12678.00');
        $eur->setExchangeRate('5.0712');
        $eur->setPaidAmount('500.00');

        $view = (new ClaimPositionsPresenter())->present($case, null, new \DateTimeImmutable(self::REFERENCE));
        $row = $view->rows[0];

        self::assertEqualsWithDelta(10142.40, $row->restRon, 0.01);
        self::assertEqualsWithDelta(2535.60, $row->paidRon, 0.01);
        self::assertEqualsWithDelta(2535.60, $view->paidRonTotal, 0.01);
        self::assertTrue($view->hasForeignCurrency);
    }

    public function testInterestIsTakenFromTheAggregateWithoutRecomputing(): void
    {
        $case = new LegalCase();
        $item = $this->item($case, 'F-1', '1000.00', '2026-03-05');
        $period = new InterestPeriod(new \DateTimeImmutable('2026-03-05'), new \DateTimeImmutable('2026-10-29'), 6.5, 14.5, 238, 94.55);
        $interest = new InterestResult(123.45, [$period], new \DateTimeImmutable('2026-03-05'), new \DateTimeImmutable(self::REFERENCE));
        $aggregate = new AggregatedAccessoryResult(123.45, interestByItemId: [$item->getId() => $interest]);

        $row = (new ClaimPositionsPresenter())->present($case, $aggregate, new \DateTimeImmutable(self::REFERENCE))->rows[0];

        self::assertSame($interest, $row->interest);
        self::assertSame(123.45, $row->accessoryTotal, 'The figure the aggregate holds, not one recomputed from the period.');
        self::assertSame('interest', $row->breakdownKind());
        self::assertNull($row->penalty);
    }

    public function testPenaltyRowCarriesTheContractualCap(): void
    {
        $case = new LegalCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $case->setContractualPenaltyRate('0.1000');
        $case->setContractualPenaltyCapPercent('10.00');
        $item = $this->item($case, 'KSS 1534', '21318.90', '2025-02-21');
        $penalty = new PenaltyResult(2131.89, [new PenaltyPeriod(new \DateTimeImmutable('2025-02-21'), new \DateTimeImmutable('2026-10-03'), 0.1, 589, 12556.83)], 2131.89);
        $aggregate = new AggregatedAccessoryResult(2131.89, penaltyByItemId: [$item->getId() => $penalty]);

        $row = (new ClaimPositionsPresenter())->present($case, $aggregate, new \DateTimeImmutable('2026-10-03'))->rows[0];

        self::assertSame('penalty', $row->breakdownKind());
        self::assertNotNull($row->penalty);
        self::assertSame('2025-02-22', $row->penalty->firstDay->format('Y-m-d'));
        self::assertSame(10.0, $row->penalty->capPercent);
        self::assertSame(2131.89, $row->penalty->capAmount);
        self::assertSame(2131.89, $row->accessoryTotal);
    }

    public function testExcludedPositionsCarryNoAccessoryEvenIfTheAggregateHasOne(): void
    {
        $case = new LegalCase();
        $item = $this->item($case, 'F-1', '1000.00', '2026-03-05');
        $item->setExcludedByLawyer(true);
        $interest = new InterestResult(10.0, [], new \DateTimeImmutable('2026-03-05'), new \DateTimeImmutable(self::REFERENCE));
        $aggregate = new AggregatedAccessoryResult(10.0, interestByItemId: [$item->getId() => $interest]);

        $row = (new ClaimPositionsPresenter())->present($case, $aggregate, new \DateTimeImmutable(self::REFERENCE))->rows[0];

        self::assertNull($row->interest);
        self::assertNull($row->accessoryTotal);
        self::assertNull($row->breakdownKind());
    }

    public function testFiltersAndPagingStartAboveSixPositions(): void
    {
        $case = new LegalCase();
        for ($i = 1; $i <= 6; ++$i) {
            $this->item($case, 'F-' . $i, '100.00', sprintf('2026-03-%02d', $i));
        }
        $presenter = new ClaimPositionsPresenter();
        $six = $presenter->present($case, null, new \DateTimeImmutable(self::REFERENCE));
        self::assertFalse($six->showFilters());

        for ($i = 7; $i <= 9; ++$i) {
            $this->item($case, 'F-' . $i, '100.00', sprintf('2026-03-%02d', $i));
        }
        $nine = $presenter->present($case, null, new \DateTimeImmutable(self::REFERENCE));
        self::assertTrue($nine->showFilters());
        self::assertTrue($nine->isPaged());
        self::assertSame(8, $nine->visibleLimit());
    }

    /** @return array<string, ClaimPositionRow> */
    private function byNumber(\App\DTO\Case\ClaimPositionsView $view): array
    {
        $rows = [];
        foreach ($view->rows as $row) {
            $rows[(string) $row->item->getDocumentNumber()] = $row;
        }

        return $rows;
    }

    private function item(LegalCase $case, string $number, string $amount, string $dueDate, string $currency = 'RON', ?string $amountRon = 'same'): ClaimItem
    {
        $item = new ClaimItem();
        $item->setDocumentNumber($number);
        $item->setAmount($amount);
        $item->setCurrency($currency);
        $item->setAmountRon($amountRon === 'same' ? $amount : $amountRon);
        $item->setDueDate(new \DateTimeImmutable($dueDate));
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('presenter-' . $number);
        (new \ReflectionProperty(ClaimItem::class, 'id'))->setValue($item, $this->nextId++);
        $case->addClaimItem($item);

        return $item;
    }
}
