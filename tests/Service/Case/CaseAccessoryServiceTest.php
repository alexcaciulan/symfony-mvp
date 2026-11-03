<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Case\CaseAccessoryService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the NBR rates actually stored, the ones the summons uses.
 */
final class CaseAccessoryServiceTest extends KernelTestCase
{
    private CaseAccessoryService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(CaseAccessoryService::class);
    }

    public function testTheReferenceDateIsTheSummonsDateOrTheCreationDateBeforeIt(): void
    {
        $case = $this->legalCase();
        self::assertSame($case->getCreatedAt()->format('Y-m-d'), $this->service->referenceDate($case)->format('Y-m-d'));

        $case->setPaymentNoticeDate(new \DateTime('-5 days'));
        self::assertSame((new \DateTimeImmutable('-5 days'))->format('Y-m-d'), $this->service->referenceDate($case)->format('Y-m-d'));

        $case->setAccessoryCutoffDate(new \DateTimeImmutable('-20 days'));
        self::assertSame((new \DateTimeImmutable('-20 days'))->format('Y-m-d'), $this->service->referenceDate($case)->format('Y-m-d'));
    }

    public function testNothingCountingLeavesTheStoredTotalAlone(): void
    {
        $case = $this->legalCase();
        $case->setCalculatedInterest('123.45');
        $this->position($case, new \DateTimeImmutable('-90 days'))->setExcludedByLawyer(true);

        self::assertNull($this->service->aggregate($case));
        self::assertNull($this->service->refreshStoredTotal($case));
        self::assertSame('123.45', $case->getCalculatedInterest());
    }

    public function testAPositionDueAfterTheReferenceDateYieldsAZeroTotal(): void
    {
        $case = $this->legalCase();
        $case->setPaymentNoticeDate(new \DateTime('-30 days'));
        $this->position($case, new \DateTimeImmutable('-10 days'));

        $change = $this->service->refreshStoredTotal($case);

        self::assertSame(['from' => null, 'to' => '0.00'], $change);
        self::assertSame('0.00', $case->getCalculatedInterest());
        self::assertNull($this->service->refreshStoredTotal($case), 'A second pass finds nothing to move.');
    }

    public function testAnOverduePositionWritesThePositiveTotal(): void
    {
        $case = $this->legalCase();
        $this->position($case, new \DateTimeImmutable('-90 days'));

        $change = $this->service->refreshStoredTotal($case);

        self::assertNotNull($change);
        self::assertNull($change['from']);
        self::assertGreaterThan(0.0, (float) $change['to']);
        self::assertSame($change['to'], $case->getCalculatedInterest());
        self::assertNull($this->service->refreshStoredTotal($case), 'A second pass finds nothing to move.');
    }

    public function testAnExcludedPositionNextToACountingOneDoesNotAccrue(): void
    {
        $case = $this->legalCase();
        $this->position($case, new \DateTimeImmutable('-90 days'));
        $this->position($case, new \DateTimeImmutable('-400 days'))->setExcludedByLawyer(true);

        $counting = $this->legalCase();
        $this->position($counting, new \DateTimeImmutable('-90 days'));

        $this->service->refreshStoredTotal($case);
        $this->service->refreshStoredTotal($counting);

        self::assertSame($counting->getCalculatedInterest(), $case->getCalculatedInterest());
    }

    public function testAContractualPenaltyWithARateIsStoredFromThePenaltyCalculator(): void
    {
        $case = $this->legalCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $case->setContractualPenaltyRate('0.100');
        $due = new \DateTimeImmutable('-90 days');
        $this->position($case, $due);

        $expected = self::getContainer()->get(ContractualPenaltyCalculator::class)->calculate(
            amount: 1000.0,
            dailyRatePercent: 0.1,
            startDate: $due,
            referenceDate: $this->service->referenceDate($case),
            capPercent: null,
            capBase: 1000.0,
        )->total;
        self::assertGreaterThan(0.0, $expected);

        $change = $this->service->refreshStoredTotal($case);

        self::assertSame(['from' => null, 'to' => sprintf('%.2f', $expected)], $change);
        self::assertSame(sprintf('%.2f', $expected), $case->getCalculatedInterest());
        $aggregate = $this->service->aggregate($case);
        self::assertNotNull($aggregate);
        self::assertSame([], $aggregate->interestByItemId, 'A contractual penalty replaces the legal interest.');
        self::assertCount(1, $aggregate->penaltyByItemId);
    }

    public function testAnUnchangedTotalIsNotReportedAsAChange(): void
    {
        $case = $this->legalCase();
        $this->position($case, new \DateTimeImmutable('-90 days'));
        $this->service->refreshStoredTotal($case);
        $stored = $case->getCalculatedInterest();
        self::assertNotNull($stored);

        // A figure that differs only beyond the bani is the same figure.
        $case->setCalculatedInterest(sprintf('%.4f', (float) $stored + 0.004));

        self::assertNull($this->service->refreshStoredTotal($case));
    }

    private function legalCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        $case->setPenaltyType(PenaltyType::LEGAL_PENALIZATOARE);

        return $case;
    }

    private function position(LegalCase $case, ?\DateTimeImmutable $dueDate): ClaimItem
    {
        $item = new ClaimItem();
        $item->setDocumentNumber('F-1');
        $item->setAmount('1000.00');
        $item->setCurrency('RON');
        $item->setAmountRon('1000.00');
        $item->setDueDate($dueDate);
        $item->setConfirmedByLawyer(true);
        $case->addClaimItem($item);

        return $item;
    }
}
