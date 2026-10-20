<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Enum\ClaimItemKind;
use App\Enum\IssueSeverity;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Case\AccessoryComputabilityChecker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the NBR rates actually stored, the ones the summons uses.
 */
final class AccessoryComputabilityCheckerTest extends KernelTestCase
{
    private AccessoryComputabilityChecker $checker;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->checker = self::getContainer()->get(AccessoryComputabilityChecker::class);
        $this->today = new \DateTimeImmutable('today');
    }

    public function testComputablePositionsReportNothing(): void
    {
        $case = $this->legalCase();
        $this->position($case, 'F-1', new \DateTimeImmutable('-90 days'));
        // A 2012 contract is computed at NBR + 4 pp, not refused.
        $case->setContractDate(new \DateTime('2012-05-10'));

        self::assertSame([], $this->checker->issues($case, $this->today));
    }

    public function testAPositionWithoutDueDateIsReportedWithItsNumber(): void
    {
        $case = $this->legalCase();
        $this->position($case, 'F-1', new \DateTimeImmutable('-90 days'));
        $this->position($case, 'F-2', null);

        $issues = $this->checker->issues($case, $this->today);

        self::assertCount(1, $issues);
        self::assertSame(IssueSeverity::ERROR, $issues[0]->severity);
        self::assertSame(AccessoryComputabilityChecker::CODE, $issues[0]->code);
        self::assertSame('validation.accessory_not_computable.position.due_date_missing', $issues[0]->messageKey);
        self::assertSame(['%position%' => '2 (F-2)'], $issues[0]->params);
    }

    public function testAPositionDueBeforeOg13Of2011IsReported(): void
    {
        $case = $this->legalCase();
        $this->position($case, '', new \DateTimeImmutable('2011-08-31'));

        $issues = $this->checker->issues($case, $this->today);

        self::assertCount(1, $issues);
        self::assertSame('validation.accessory_not_computable.position.due_date_before_rate_history', $issues[0]->messageKey);
        self::assertSame(['%position%' => '1'], $issues[0]->params);
    }

    public function testAContractualPenaltyWithARateStillNeedsTheDueDate(): void
    {
        $case = $this->legalCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $case->setContractualPenaltyRate('0.100');
        $this->position($case, 'F-3', null);

        $issues = $this->checker->issues($case, $this->today);

        self::assertCount(1, $issues);
        self::assertSame('validation.accessory_not_computable.position.due_date_missing', $issues[0]->messageKey);
    }

    public function testAContractualPenaltyWithoutRateIsNotComputedSoNothingFails(): void
    {
        $case = $this->legalCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->position($case, 'F-4', null);

        self::assertSame([], $this->checker->issues($case, $this->today));
    }

    public function testACreditNoteWithoutDueDateAccruesNothingByDesign(): void
    {
        $case = $this->legalCase();
        $this->position($case, 'F-5', new \DateTimeImmutable('-90 days'));
        $this->position($case, 'NC-1', null)->setKind(ClaimItemKind::CREDIT_NOTE);

        self::assertSame([], $this->checker->issues($case, $this->today));
    }

    public function testACaseWithoutPositionsNeedsItsOwnDueDate(): void
    {
        $case = $this->legalCase();

        $issues = $this->checker->issues($case, $this->today);

        self::assertCount(1, $issues);
        self::assertSame('validation.accessory_not_computable.claim.due_date_missing', $issues[0]->messageKey);
        self::assertSame([], $issues[0]->params);

        $case->setDueDate(new \DateTime('-30 days'));
        self::assertSame([], $this->checker->issues($case, $this->today));
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

    private function position(LegalCase $case, string $number, ?\DateTimeImmutable $dueDate): ClaimItem
    {
        $item = new ClaimItem();
        $item->setDocumentNumber($number);
        $item->setAmount('1000.00');
        $item->setCurrency('RON');
        $item->setAmountRon('1000.00');
        $item->setDueDate($dueDate);
        $item->setConfirmedByLawyer(true);
        $case->addClaimItem($item);

        return $item;
    }
}
