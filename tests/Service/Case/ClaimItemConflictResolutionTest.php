<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Wizard\ClaimItemRow;
use App\Enum\ConflictScope;
use App\Service\Case\ClaimItemFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The same invoice read twice with two sums is the disagreement that decides
 * what is asked of the court. Once the lawyer says which reading holds, it has
 * to be the one that reaches the table, the totals and the interest, and the
 * row has to stop warning about a divergence that is settled.
 */
final class ClaimItemConflictResolutionTest extends KernelTestCase
{
    private ClaimItemFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = static::getContainer()->get(ClaimItemFactory::class);
    }

    public function testTheChosenSumReplacesTheOneTheRankingKept(): void
    {
        $row = $this->row(1000.0);
        $row->warningKeys[] = 'wizard.step3.claim_items.warning.amount_mismatch';

        $this->factory->applyResolutions([$row], [
            $this->resolution('amount', 1200.0, 'inv:FF-100'),
        ]);

        self::assertSame(1200.0, $row->amount);
        // The RON figure is what the totals and the stamp duty read.
        self::assertSame(1200.0, $row->amountRon);
        self::assertNotContains('wizard.step3.claim_items.warning.amount_mismatch', $row->warningKeys);
    }

    public function testTheChosenDueDateReplacesTheOneTheRankingKept(): void
    {
        $row = $this->row(1000.0);
        $row->warningKeys[] = 'wizard.step3.claim_items.warning.due_date_mismatch';

        $this->factory->applyResolutions([$row], [
            $this->resolution('dueDate', new \DateTimeImmutable('2025-03-31'), 'inv:FF-100'),
        ]);

        self::assertSame('2025-03-31', $row->dueDate?->format('Y-m-d'));
        self::assertNotContains('wizard.step3.claim_items.warning.due_date_mismatch', $row->warningKeys);
    }

    public function testADecisionAboutOneInvoiceLeavesTheOthersAlone(): void
    {
        // Matched on the dedup key the conflict was raised against: the rows are
        // rebuilt from the documents on every request, so a sum bound to a table
        // position would land on another invoice.
        $first = $this->row(1000.0, 'inv:FF-100');
        $second = $this->row(2000.0, 'inv:FF-200');

        $this->factory->applyResolutions([$first, $second], [
            $this->resolution('amount', 1200.0, 'inv:FF-100'),
        ]);

        self::assertSame(1200.0, $first->amount);
        self::assertSame(2000.0, $second->amount);
    }

    private function row(float $amount, string $key = 'inv:FF-100'): ClaimItemRow
    {
        return new ClaimItemRow(
            dedupKey: $key,
            amount: $amount,
            currency: 'RON',
            documentNumber: 'FF-100',
            documentDate: new \DateTimeImmutable('2025-01-01'),
            dueDate: new \DateTimeImmutable('2025-01-31'),
            amountRon: $amount,
        );
    }

    private function resolution(string $field, mixed $value, string $entityKey): ConflictResolution
    {
        return new ConflictResolution(
            conflictKey: 'claim_item:' . $entityKey . ':' . $field . ':wizard.conflict.claim_item.mismatch',
            scope: ConflictScope::CLAIM_ITEM,
            field: $field,
            entityKey: $entityKey,
            value: $value,
            optionIndex: 1,
            documentId: 7,
            optionSignature: 'signature',
        );
    }
}
