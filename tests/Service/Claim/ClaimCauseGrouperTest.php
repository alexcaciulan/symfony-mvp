<?php

declare(strict_types=1);

namespace App\Tests\Service\Claim;

use App\Entity\ClaimItem;
use App\Enum\ClaimItemKind;
use App\Service\Claim\ClaimCauseGrouper;
use PHPUnit\Framework\TestCase;

/**
 * The grouper is the single source of truth for CPC art. 99 valuation: the
 * competent court and the note justifying the cumulative object value on the
 * petition filed at it must read the positions the same way. These lock the
 * grouping so the two can never diverge again.
 */
final class ClaimCauseGrouperTest extends TestCase
{
    private ClaimCauseGrouper $grouper;

    protected function setUp(): void
    {
        $this->grouper = new ClaimCauseGrouper();
    }

    public function testSingleStatedCauseIsOneCause(): void
    {
        $items = [
            $this->item(1, 5000.0, 'Contract 10/2024'),
            $this->item(2, 5000.0, 'Contract 10/2024'),
        ];

        [$byCause, $uncertain] = $this->grouper->group($items);

        self::assertFalse($uncertain);
        self::assertCount(1, $byCause);
        self::assertSame(10000.0, $byCause['contract102024'] ?? array_values($byCause)[0]);
        self::assertTrue($this->grouper->isSingleCause($items));
    }

    public function testDistinctStatedCausesAreNotOneCause(): void
    {
        $items = [
            $this->item(1, 5000.0, 'Contract 10/2024'),
            $this->item(2, 5000.0, 'Contract 99/2024'),
        ];

        [$byCause, $uncertain] = $this->grouper->group($items);

        self::assertFalse($uncertain);
        self::assertCount(2, $byCause);
        self::assertFalse($this->grouper->isSingleCause($items));
    }

    /**
     * The scenario the blocker turned on: an unlabelled invoice joins the file's
     * one stated cause instead of counting as a second cause, so the summed total
     * is one head and the art. 99 note stands.
     */
    public function testUnlabelledPositionJoinsTheSingleStatedCause(): void
    {
        $items = [
            $this->item(1, 140000.0, 'Contract X'),
            $this->item(2, 90000.0, null),
        ];

        [$byCause, $uncertain] = $this->grouper->group($items);

        self::assertFalse($uncertain);
        self::assertCount(1, $byCause);
        self::assertSame(230000.0, array_values($byCause)[0]);
        self::assertTrue($this->grouper->isSingleCause($items));
    }

    public function testAllUnlabelledFormOneCause(): void
    {
        $items = [
            $this->item(1, 5000.0, null),
            $this->item(2, 3000.0, null),
        ];

        [$byCause, $uncertain] = $this->grouper->group($items);

        self::assertFalse($uncertain);
        self::assertSame(['' => 8000.0], $byCause);
        self::assertTrue($this->grouper->isSingleCause($items));
    }

    /**
     * Several stated causes plus unlabelled positions: the unlabelled ones cannot
     * be attributed to any one cause, so the grouping is uncertain and the file is
     * not a single cause.
     */
    public function testSeveralCausesPlusUnlabelledIsUncertain(): void
    {
        $items = [
            $this->item(1, 5000.0, 'Contract 10/2024'),
            $this->item(2, 5000.0, 'Contract 99/2024'),
            $this->item(3, 2000.0, null),
        ];

        [$byCause, $uncertain] = $this->grouper->group($items);

        self::assertTrue($uncertain);
        self::assertFalse($this->grouper->isSingleCause($items));
        self::assertSame(2000.0, $byCause['']);
    }

    public function testCreditNoteReducesItsCauseWithNegativeSign(): void
    {
        $items = [
            $this->item(1, 5000.0, 'Contract 10/2024'),
            $this->item(2, 1000.0, 'Contract 10/2024', ClaimItemKind::CREDIT_NOTE),
        ];

        [$byCause] = $this->grouper->group($items);

        self::assertSame(4000.0, array_values($byCause)[0]);
    }

    public function testPositionWithUnresolvedRonValueIsSkipped(): void
    {
        $withValue = $this->item(1, 5000.0, 'Contract 10/2024');
        $noValue = new ClaimItem();
        $noValue->setKind(ClaimItemKind::INVOICE);
        $noValue->setCauseReference('Contract 99/2024');

        [$byCause] = $this->grouper->group([$withValue, $noValue]);

        self::assertCount(1, $byCause);
        self::assertSame(5000.0, array_values($byCause)[0]);
    }

    private function item(int $id, float $amount, ?string $cause, ClaimItemKind $kind = ClaimItemKind::INVOICE): ClaimItem
    {
        $item = new ClaimItem();
        $item->setKind($kind);
        $item->setAmount(sprintf('%.2f', $amount));
        $item->setAmountRon(sprintf('%.2f', $amount));
        $item->setCurrency('RON');
        $item->setCauseReference($cause);
        $item->setDedupKey('inv:' . $id);

        return $item;
    }
}
