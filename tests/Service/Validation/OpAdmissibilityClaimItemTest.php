<?php

declare(strict_types=1);

namespace App\Tests\Service\Validation;

use App\DTO\Validation\AdmissibilityIssue;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Enum\IssueSeverity;
use App\Service\Validation\OpAdmissibilityValidator;
use PHPUnit\Framework\TestCase;

/**
 * Exigibility (CPC art. 1013) evaluated per claim position. Kept in the one
 * validator that owns admissibility, so no second engine can drift from it.
 */
class OpAdmissibilityClaimItemTest extends TestCase
{
    private const NOW = '2026-05-09 12:00:00';

    public function testAFuturePositionBlocksEvenWhenAnotherIsOverdue(): void
    {
        // The case scalar is the earliest due date, so it is in the past here
        // and the case-level rule alone would let this through.
        $case = $this->caseWithItems(['2026-01-31', '2026-12-31']);
        $case->setDueDate(new \DateTime('2026-01-31'));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertSame(['OP_ITEM_NOT_YET_DUE'], $this->codes($issues));
        $this->assertSame(IssueSeverity::ERROR, $issues[0]->severity);
    }

    public function testOverduePositionsRaiseNothing(): void
    {
        $case = $this->caseWithItems(['2026-01-31', '2026-02-28']);

        $this->assertSame([], (new OpAdmissibilityValidator())->validate($case, $this->now()));
    }

    public function testAPositionWithoutADueDateIsWarnedAbout(): void
    {
        $case = $this->caseWithItems(['2026-01-31', null]);

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertSame(['OP_ITEM_DUE_DATE_MISSING'], $this->codes($issues));
        $this->assertSame(IssueSeverity::WARNING, $issues[0]->severity);
    }

    public function testTheCaseLevelRuleStillAppliesWithoutPositions(): void
    {
        $case = new LegalCase();
        $case->setDueDate(new \DateTime('2026-12-31'));

        $this->assertSame(['OP_DEBT_NOT_YET_DUE'], $this->codes((new OpAdmissibilityValidator())->validate($case, $this->now())));
    }

    public function testAnExcludedFuturePositionDoesNotBlock(): void
    {
        $case = $this->caseWithItems(['2026-01-31']);
        $excluded = $this->item('2026-12-31');
        $excluded->setExcludedByLawyer(true);
        $case->addClaimItem($excluded);

        $this->assertSame([], (new OpAdmissibilityValidator())->validate($case, $this->now()));
    }

    public function testAPositionOlderThanThreeYearsIsFlaggedAsPrescribed(): void
    {
        // Each invoice prescribes from its own due date (Civil Code art. 2517 +
        // art. 2523), so a stale one hidden among current invoices has to be
        // named, not absorbed into a total.
        $case = $this->caseWithItems(['2026-01-31', '2021-02-04']);

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertSame(['OP_ITEM_PRESCRIBED'], $this->codes($issues));
        $this->assertSame(
            IssueSeverity::WARNING,
            $issues[0]->severity,
            'The court does not raise prescription of its own motion (art. 2512): keeping the head is the lawyer decision.',
        );
    }

    public function testAPositionExactlyAtTheThreeYearMarkIsNotFlagged(): void
    {
        $case = $this->caseWithItems(['2023-05-09']);

        $this->assertSame([], $this->codes((new OpAdmissibilityValidator())->validate($case, $this->now())));
    }

    public function testARecordedPaymentIsSurfacedInsteadOfSilentlyIgnored(): void
    {
        $case = $this->caseWithItems(['2026-01-31']);
        $case->getClaimItems()->first()->setPaidAmount('4000.00');

        $this->assertSame(
            ['OP_ITEM_UNIMPUTED_PAYMENT'],
            $this->codes((new OpAdmissibilityValidator())->validate($case, $this->now())),
        );
    }

    public function testADeductionStatedOnTheDocumentIsSurfaced(): void
    {
        $case = $this->caseWithItems(['2026-01-31']);
        $case->getClaimItems()->first()->setDescription('Total factura, avans incasat 4.000 lei');

        $this->assertSame(
            ['OP_ITEM_STATED_DEDUCTION'],
            $this->codes((new OpAdmissibilityValidator())->validate($case, $this->now())),
        );
    }

    /** @param list<?string> $dueDates */
    private function caseWithItems(array $dueDates): LegalCase
    {
        $case = new LegalCase();
        foreach ($dueDates as $dueDate) {
            $case->addClaimItem($this->item($dueDate));
        }

        return $case;
    }

    private function item(?string $dueDate): ClaimItem
    {
        $item = new ClaimItem();
        $item->setAmount('1000.00');
        $item->setAmountRon('1000.00');
        $item->setCurrency('RON');
        $item->setDueDate($dueDate !== null ? new \DateTimeImmutable($dueDate) : null);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:' . ($dueDate ?? 'none'));

        return $item;
    }

    /**
     * @param  list<AdmissibilityIssue> $issues
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (AdmissibilityIssue $i): string => $i->code, $issues);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
