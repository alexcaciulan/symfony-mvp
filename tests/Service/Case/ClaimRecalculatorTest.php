<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Validation\AdmissibilityIssue;
use App\Entity\AuditLog;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\CaseStatus;
use App\Enum\ClaimItemKind;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
use App\Service\Case\ClaimRecalculator;
use App\Service\Deadline\DeadlineService;
use App\Service\Document\SummonsContextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the courts and the NBR rates actually stored, the ones the
 * summons uses.
 */
final class ClaimRecalculatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ClaimRecalculator $recalculator;
    private DeadlineService $deadlineService;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->recalculator = $container->get(ClaimRecalculator::class);
        $this->deadlineService = $container->get('test.public.deadline_service');

        $this->user = new User();
        $this->user->setEmail('claim-recalc-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        $conn->executeStatement('DELETE FROM claim_item WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM debtor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    public function testThreePositionsSetTheTotalsTheAccessoryAndTheTerms(): void
    {
        $case = $this->makeCase();
        $before = $this->recalculator->snapshot($case);
        $this->position($case, 'F-1', '1000.00', '-400 days');
        $this->position($case, 'F-2', '2000.00', '-300 days');
        $this->position($case, 'F-3', '3000.00', '-200 days');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $before, $this->user);

        self::assertSame('6000.00', $case->getAmount());
        self::assertSame(6000.0, $result->totals->principalRon);
        self::assertSame(3, $result->totals->countedCount);
        self::assertNotNull($case->getCalculatedInterest());
        self::assertGreaterThan(0.0, (float) $case->getCalculatedInterest());
        self::assertNotNull($result->accessoryChange);
        self::assertNull($result->accessoryChange['from'], 'A fresh case carries no stored accessory.');
        self::assertSame($case->getCalculatedInterest(), $result->accessoryChange['to']);
        self::assertSame(
            [$this->termEnd('-400 days'), $this->termEnd('-300 days'), $this->termEnd('-200 days')],
            $this->dates($result->deadlinesCreated),
        );
        self::assertSame([], $result->deadlinesRemoved);
        self::assertSame([], $result->deadlinesUnmatched);
        self::assertCount(3, $this->prescriptionDeadlines($case));
        self::assertSame([], $result->issues['errors']);
        self::assertSame($case->getCreatedAt()->format('Y-m-d'), $result->referenceDate->format('Y-m-d'));
        self::assertNull($result->fxChange);
        self::assertSame($case->getCreatedAt()->format('Y-m-d'), $this->recalculator->snapshot($case)['totals']['referenceDate']);
    }

    public function testASingleConvertedPositionWritesTheTraceTheSummonsPrintsAndASecondOneClearsIt(): void
    {
        $case = $this->makeCase();
        $before = $this->recalculator->snapshot($case);
        $this->euroPosition($case, 'F-EUR-1', '1000.00', '4975.00', '-100 days');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $before, $this->user);

        self::assertSame('4975.00', $case->getAmount());
        self::assertSame('1000.00', $case->getOriginalAmount());
        self::assertSame('EUR', $case->getOriginalCurrency());
        self::assertSame('4.9750', $case->getExchangeRate());
        self::assertSame((new \DateTimeImmutable('-130 days'))->format('Y-m-d'), $case->getExchangeRateDate()?->format('Y-m-d'));
        self::assertNotNull($result->fxChange);
        self::assertNull($result->fxChange['from']['originalCurrency']);
        self::assertSame('EUR', $result->fxChange['to']['originalCurrency']);
        $context = $this->summonsContext($case);
        self::assertSame(1000.0, $context['originalAmount']);
        self::assertSame('EUR', $context['originalCurrency']);
        self::assertSame(4.975, $context['exchangeRate']);

        $before = $this->recalculator->snapshot($case);
        self::assertSame('EUR', $before['fx']['originalCurrency']);
        $this->euroPosition($case, 'F-EUR-2', '500.00', '2487.50', '-50 days');
        $result = $this->recalculator->recalculate($case, 'add_manual', $before, $this->user);

        self::assertSame('7462.50', $case->getAmount());
        self::assertNull($case->getOriginalAmount());
        self::assertNull($case->getOriginalCurrency());
        self::assertNull($case->getExchangeRate());
        self::assertNull($case->getExchangeRateDate());
        self::assertNotNull($result->fxChange);
        self::assertSame('EUR', $result->fxChange['from']['originalCurrency']);
        self::assertNull($result->fxChange['to']['originalCurrency']);
        $context = $this->summonsContext($case);
        self::assertNull($context['originalAmount']);
        self::assertNull($context['originalCurrency']);
        self::assertNull($context['exchangeRate']);
    }

    public function testExcludingAPositionDeletesOnlyTheTermThatMatchesItsDueDateExactly(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-400 days');
        $this->position($case, 'F-2', '2000.00', '-300 days');
        $third = $this->position($case, 'F-3', '3000.00', '-200 days');
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        $orphanDate = $this->termEnd('-200 days');
        $orphan = $this->findPrescriptionDeadline($case, $orphanDate);
        self::assertInstanceOf(LegalDeadline::class, $orphan);
        $orphanId = $orphan->getId();

        $before = $this->recalculator->snapshot($case);
        $third->setExcludedByLawyer(true);

        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertSame('3000.00', $case->getAmount());
        self::assertSame([$orphanId], $result->deadlinesRemoved);
        self::assertSame([], $result->deadlinesUnmatched);
        self::assertSame([], $result->deadlinesCreated);
        self::assertNull($this->findPrescriptionDeadline($case, $orphanDate));
        self::assertCount(2, $this->prescriptionDeadlines($case));

        $deletion = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityType' => LegalDeadline::class,
            'entityId' => (string) $orphanId,
            'action' => 'deadline_deleted',
        ]);
        self::assertInstanceOf(AuditLog::class, $deletion);
        self::assertSame(AuditLogService::CATEGORY_DEADLINE_DELETED, $deletion->getCategory());
        self::assertSame($orphanDate, $deletion->getOldData()['deadlineDate']);
    }

    public function testATermTheLawyerMovedIsKeptAndReported(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-400 days');
        $third = $this->position($case, 'F-3', '3000.00', '-200 days');
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        $moved = $this->findPrescriptionDeadline($case, $this->termEnd('-200 days'));
        self::assertInstanceOf(LegalDeadline::class, $moved);
        $moved->setDeadlineDate($moved->getDeadlineDate()->modify('-1 day'));
        $this->em->flush();

        $before = $this->recalculator->snapshot($case);
        $third->setExcludedByLawyer(true);

        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertSame([], $result->deadlinesRemoved);
        self::assertCount(1, $result->deadlinesUnmatched);
        self::assertSame($moved->getId(), $result->deadlinesUnmatched[0]->getId());
        self::assertCount(2, $this->prescriptionDeadlines($case));
    }

    public function testTwoInvoicesWithTheSameDueDateKeepTheTermUntilBothLeave(): void
    {
        $case = $this->makeCase();
        $first = $this->position($case, 'F-1', '1000.00', '-300 days');
        $second = $this->position($case, 'F-2', '2000.00', '-300 days');
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        self::assertCount(1, $this->prescriptionDeadlines($case));

        $before = $this->recalculator->snapshot($case);
        $first->setExcludedByLawyer(true);
        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertSame([], $result->deadlinesRemoved);
        self::assertSame([], $result->deadlinesUnmatched);
        self::assertCount(1, $this->prescriptionDeadlines($case));

        $before = $this->recalculator->snapshot($case);
        $second->setExcludedByLawyer(true);
        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertCount(1, $result->deadlinesRemoved);
        self::assertSame([], $this->prescriptionDeadlines($case));
    }

    public function testACreditNoteLeavingTheClaimDeletesNoTerm(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-300 days');
        $note = $this->position($case, 'NC-1', '100.00', '-200 days');
        $note->setKind(ClaimItemKind::CREDIT_NOTE);
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        self::assertSame([$this->termEnd('-300 days')], $this->dates($this->prescriptionDeadlines($case)), 'A credit note gets no term of its own.');

        // A term the lawyer entered by hand that happens to sit on the date a
        // credit note would have produced: nothing proves it obsolete.
        $manual = $this->manualPrescriptionDeadline($case, $this->termEnd('-200 days'));

        $before = $this->recalculator->snapshot($case);
        self::assertSame('credit_note', $before['items'][1]['kind']);
        $note->setExcludedByLawyer(true);
        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertSame('1000.00', $case->getAmount());
        self::assertSame([], $result->deadlinesRemoved);
        self::assertSame([$manual->getId()], array_map(static fn (LegalDeadline $d): ?int => $d->getId(), $result->deadlinesUnmatched));
        self::assertCount(2, $this->prescriptionDeadlines($case));
    }

    public function testACompletedTermIsNeitherDeletedNorReported(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-300 days');
        $third = $this->position($case, 'F-3', '3000.00', '-200 days');
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        $done = $this->findPrescriptionDeadline($case, $this->termEnd('-200 days'));
        self::assertInstanceOf(LegalDeadline::class, $done);
        $done->markCompleted($this->user);
        $this->em->flush();

        $before = $this->recalculator->snapshot($case);
        $third->setExcludedByLawyer(true);
        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertSame([], $result->deadlinesRemoved);
        self::assertSame([], $result->deadlinesUnmatched);
        $kept = $this->findPrescriptionDeadline($case, $this->termEnd('-200 days'));
        self::assertInstanceOf(LegalDeadline::class, $kept);
        self::assertTrue($kept->isCompleted());
        self::assertCount(2, $this->prescriptionDeadlines($case));
    }

    public function testWithoutABeforeSnapshotNothingIsDeletedAndTheOpenTermsAreReported(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-300 days');
        $third = $this->position($case, 'F-3', '3000.00', '-200 days');
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);

        $third->setExcludedByLawyer(true);
        $result = $this->recalculator->recalculate($case, 'exclude', [], $this->user);

        self::assertSame([], $result->deadlinesRemoved);
        self::assertSame([$this->termEnd('-200 days')], $this->dates($result->deadlinesUnmatched));
        self::assertCount(2, $this->prescriptionDeadlines($case));
    }

    public function testAPositionAwaitingAManualRateLeavesTheScalarsAndTheAccessoryAlone(): void
    {
        $case = $this->makeCase();
        $ron = $this->position($case, 'F-1', '1000.00', '-300 days');
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        $storedAccessory = $case->getCalculatedInterest();
        self::assertGreaterThan(0.0, (float) $storedAccessory);

        $before = $this->recalculator->snapshot($case);
        $ron->setExcludedByLawyer(true);
        $pending = $this->position($case, 'F-USD', '500.00', '-300 days');
        $pending->setCurrency('USD');
        $pending->setAmountRon(null);
        $pending->setNeedsManualFx(true);
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertSame(0, $result->totals->countedCount);
        self::assertSame(1, $result->totals->needsManualFxCount);
        self::assertSame('1000.00', $case->getAmount(), 'The stored scalars are the only figures anyone has.');
        self::assertSame((new \DateTimeImmutable('-300 days'))->format('Y-m-d'), $case->getDueDate()?->format('Y-m-d'));
        self::assertSame($storedAccessory, $case->getCalculatedInterest());
        self::assertNull($result->accessoryChange);
        self::assertNull($result->courtProposal);
        self::assertSame([], $result->deadlinesRemoved);
        self::assertSame([$this->termEnd('-300 days')], $this->dates($this->prescriptionDeadlines($case)));
    }

    public function testTheFxTraceSurvivesAPositionAwaitingAManualRate(): void
    {
        $case = $this->makeCase();
        $ron = $this->position($case, 'F-1', '1000.00', '-300 days');
        $case->setOriginalAmount('200.00');
        $case->setOriginalCurrency('EUR');
        $case->setExchangeRate('5.0000');
        $case->setExchangeRateDate(new \DateTimeImmutable('2024-01-02'));
        $this->em->flush();

        $before = $this->recalculator->snapshot($case);
        $ron->setExcludedByLawyer(true);
        $pending = $this->position($case, 'F-USD', '500.00', '-300 days');
        $pending->setCurrency('USD');
        $pending->setAmountRon(null);
        $pending->setNeedsManualFx(true);
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertNull($result->fxChange);
        self::assertSame('200.00', $case->getOriginalAmount());
        self::assertSame('EUR', $case->getOriginalCurrency());
        self::assertSame('5.0000', $case->getExchangeRate());
        self::assertSame('2024-01-02', $case->getExchangeRateDate()?->format('Y-m-d'));
    }

    public function testCrossingTheThresholdProposesTheHigherCourtWithoutChangingTheCase(): void
    {
        $case = $this->makeCase();
        $local = $this->court('Judecătoria Cluj-Napoca');
        $case->setCourt($local);
        $this->position($case, 'F-1', '150000.00', '-100 days', 'Contract 7/2024');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        self::assertNull($result->courtProposal, 'Within the threshold the stored court is the competent one.');

        $before = $this->recalculator->snapshot($case);
        $this->position($case, 'F-2', '100000.00', '-50 days', 'Contract 7/2024');
        $result = $this->recalculator->recalculate($case, 'add_manual', $before, $this->user);

        self::assertNotNull($result->courtProposal);
        self::assertTrue($result->courtProposal->isChange());
        self::assertSame($local->getId(), $result->courtProposal->current?->getId());
        self::assertSame('Tribunalul Specializat Cluj', $result->courtProposal->proposed?->getName());
        self::assertSame($local->getId(), $case->getCourt()?->getId(), 'The court is never written by the recalculator.');
    }

    public function testADivergentCompetenceKeepsTheCourtAndReportsWhy(): void
    {
        $case = $this->makeCase();
        $local = $this->court('Judecătoria Cluj-Napoca');
        $case->setCourt($local);
        $this->position($case, 'F-1', '150000.00', '-100 days', 'Contract A');
        $this->position($case, 'F-2', '250000.00', '-50 days', 'Contract B');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);

        self::assertNotNull($result->courtProposal);
        self::assertTrue($result->courtProposal->isUnresolved());
        self::assertNull($result->courtProposal->proposed);
        self::assertSame('court.resolver.art99_divergent_competence', $result->courtProposal->explanationKey);
        self::assertSame($local->getId(), $case->getCourt()?->getId());
    }

    public function testADebtorWithoutACountyLeavesTheCourtAndSaysWhy(): void
    {
        $case = $this->makeCase(county: null, locality: null);
        $local = $this->court('Judecătoria Cluj-Napoca');
        $case->setCourt($local);
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);

        self::assertNotNull($result->courtProposal);
        self::assertTrue($result->courtProposal->isUnresolved());
        self::assertSame('court.resolver.county_unknown', $result->courtProposal->explanationKey);
        self::assertSame($local->getId(), $result->courtProposal->current?->getId());
        self::assertSame([], $result->courtProposal->candidates);
        self::assertSame($local->getId(), $case->getCourt()?->getId());
    }

    public function testACaseWithoutACourtGetsAProposalWhenTheRulesNameOne(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '6000.00', '-100 days');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);

        self::assertNotNull($result->courtProposal);
        self::assertTrue($result->courtProposal->isChange());
        self::assertNull($result->courtProposal->current);
        self::assertSame('Judecătoria Cluj-Napoca', $result->courtProposal->proposed?->getName());
        self::assertNull($case->getCourt(), 'The court is never written by the recalculator.');
    }

    public function testExcludingEveryPositionZeroesTheClaimAndItsAccessory(): void
    {
        $case = $this->makeCase();
        $one = $this->position($case, 'F-1', '1000.00', '-400 days');
        $two = $this->position($case, 'F-2', '2000.00', '-300 days');
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        self::assertGreaterThan(0.0, (float) $case->getCalculatedInterest());

        $before = $this->recalculator->snapshot($case);
        $one->setExcludedByLawyer(true);
        $two->setExcludedByLawyer(true);
        $result = $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        self::assertSame('0.00', $case->getAmount());
        self::assertSame('0.00', $case->getCalculatedInterest());
        self::assertSame('0.00', $result->accessoryChange['to'] ?? null);
        self::assertNull($case->getDueDate());
        self::assertSame(0, $result->totals->countedCount);
        self::assertCount(2, $result->deadlinesRemoved);
        self::assertNull($result->courtProposal);
    }

    public function testAPositionDueAfterTheReferenceDateAccruesNothingUntilTheSummons(): void
    {
        $case = $this->makeCase();
        $this->em->getConnection()->executeStatement(
            'UPDATE legal_case SET created_at = :at WHERE id = :id',
            ['at' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'), 'id' => $case->getId()],
        );
        $this->em->refresh($case);
        $before = $this->recalculator->snapshot($case);
        $this->position($case, 'F-1', '5000.00', '-10 days');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $before, $this->user);

        self::assertSame((new \DateTimeImmutable('-30 days'))->format('Y-m-d'), $result->referenceDate->format('Y-m-d'));
        self::assertSame('0.00', $case->getCalculatedInterest());
        self::assertSame([], $result->issues['errors']);
        self::assertSame([], $this->codes($result->issues['warnings']));
    }

    public function testACutoffBeforeTheDueDateIsReportedAsAWarning(): void
    {
        $case = $this->makeCase();
        $case->setAccessoryCutoffDate(new \DateTimeImmutable('-100 days'));
        $before = $this->recalculator->snapshot($case);
        $this->position($case, 'F-9', '5000.00', '-50 days');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $before, $this->user);

        self::assertSame((new \DateTimeImmutable('-100 days'))->format('Y-m-d'), $result->referenceDate->format('Y-m-d'));
        self::assertContains(ClaimRecalculator::CODE_CUTOFF_BEFORE_DUE_DATE, $this->codes($result->issues['warnings']));
        $warning = $this->issue($result->issues['warnings'], ClaimRecalculator::CODE_CUTOFF_BEFORE_DUE_DATE);
        self::assertSame('1 (F-9)', $warning->params['%position%']);
    }

    public function testAPrescribedPositionIsAWarningAndGetsNoTermInThePast(): void
    {
        $case = $this->makeCase();
        $before = $this->recalculator->snapshot($case);
        $this->position($case, 'F-OLD', '1000.00', '-4 years');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $before, $this->user);

        self::assertContains('OP_ITEM_PRESCRIBED', $this->codes($result->issues['warnings']));
        self::assertSame([], $result->deadlinesCreated);
        self::assertSame([], $this->prescriptionDeadlines($case));
    }

    public function testAPositionNotYetDueIsAnError(): void
    {
        $case = $this->makeCase();
        $before = $this->recalculator->snapshot($case);
        $this->position($case, 'F-NEW', '1000.00', '+10 days');
        $this->em->flush();

        $result = $this->recalculator->recalculate($case, 'add_manual', $before, $this->user);

        self::assertTrue($result->hasErrors());
        self::assertContains('OP_ITEM_NOT_YET_DUE', $this->codes($result->issues['errors']));
        self::assertCount(1, $result->deadlinesCreated);
    }

    public function testTheAuditEntryHoldsBeforeAndAfterWithThePersonalNumericCodeMasked(): void
    {
        $cnp = '1980715221232';
        $case = $this->makeCase();
        $item = $this->position($case, 'F-1', '1000.00', '-100 days');
        $item->setDescription('Plata CNP ' . $cnp);
        $this->em->flush();
        $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);

        $before = $this->recalculator->snapshot($case) + ['note' => 'Extras pentru CNP ' . $cnp];
        $item->setExcludedByLawyer(true);
        $this->recalculator->recalculate($case, 'exclude', $before, $this->user);

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => ClaimRecalculator::ACTION,
        ], ['id' => 'ASC']);
        self::assertCount(2, $entries);
        $entry = $entries[1];
        self::assertSame(AuditLogService::CATEGORY_CLAIM_EDITED, $entry->getCategory());
        self::assertSame($this->user->getId(), $entry->getUser()?->getId());

        $old = $entry->getOldData();
        $new = $entry->getNewData();
        self::assertSame('exclude', $new['reason']);
        self::assertSame('AMIABIL', $new['status']);
        self::assertSame($case->getCaseNumber(), $new['caseNumber']);
        self::assertFalse($old['items'][0]['excludedByLawyer']);
        self::assertTrue($new['items'][0]['excludedByLawyer']);
        self::assertSame('F-1', $new['items'][0]['documentNumber']);
        self::assertSame('manual', $new['items'][0]['source']['origin']);
        self::assertSame('1000.00', $old['totals']['principal']);
        self::assertSame('0.00', $new['totals']['principal']);
        self::assertSame('0.00', $new['totals']['accessory']);
        self::assertSame('0.00', $new['accessoryChange']['to']);
        self::assertNull($old['court']);
        self::assertNull($new['courtProposal']);
        self::assertNull($new['fxChange']);
        self::assertSame([], $new['deadlinesCreated']);
        self::assertSame([], $new['deadlinesUnmatched']);
        self::assertCount(1, $new['deadlinesRemoved']);
        self::assertSame(['errors', 'warnings'], array_keys($new['issues']));
        self::assertSame('Extras pentru CNP ***-***-1232', $old['note']);
        self::assertStringNotContainsString($cnp, json_encode($old, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($cnp, json_encode($new, JSON_THROW_ON_ERROR));
    }

    public function testSnapshotCarriesTheDetails(): void
    {
        $case = $this->makeCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $case->setContractualPenaltyRate('0.100');
        $case->setContractualPenaltyCapPercent('10.00');
        $case->setContractualAccessoryLabel(ContractualAccessoryLabel::DOBANZI_PENALIZATOARE);
        $case->setAccessoryCutoffDate(new \DateTimeImmutable('2025-06-30'));
        $case->setContractNumber('58');
        $case->setContractDate(new \DateTime('2024-01-10'));
        $case->setContractObject('servicii de transport');

        $details = $this->recalculator->snapshot($case)['details'];

        self::assertSame([
            'claimDescription', 'contractReference', 'penaltyClauseArticle', 'penaltyClauseText', 'paymentNoticeNumber',
            'penaltyType', 'contractualPenaltyRate', 'contractualPenaltyCapPercent', 'contractualAccessoryLabel',
            'accessoryCutoffDate', 'contractNumber', 'contractDate', 'contractObject',
        ], array_keys($details));
        self::assertSame('CONTRACTUAL', $details['penaltyType']);
        self::assertSame('0.100', $details['contractualPenaltyRate']);
        self::assertSame('DOBANZI_PENALIZATOARE', $details['contractualAccessoryLabel']);
        self::assertSame('2025-06-30', $details['accessoryCutoffDate']);
        self::assertSame('2024-01-10', $details['contractDate']);
    }

    public function testDetailsChangeListsOnlyChangedFields(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $case->setContractNumber('58');
        $this->em->flush();
        $before = $this->recalculator->snapshot($case);

        $case->setContractNumber('59');
        $case->setClaimDescription('Servicii');
        $result = $this->recalculator->recalculate($case, 'edit_details', $before, $this->user);

        self::assertSame([
            'claimDescription' => ['from' => null, 'to' => 'Servicii'],
            'contractNumber' => ['from' => '58', 'to' => '59'],
        ], $result->detailsChange);

        $positionsOnly = $this->recalculator->recalculate($case, 'add_manual', $this->recalculator->snapshot($case), $this->user);
        self::assertSame([], $positionsOnly->detailsChange, 'An edit of the positions moves no detail.');
    }

    private function makeCase(?string $county = 'Cluj', ?string $locality = 'Cluj-Napoca'): LegalCase
    {
        $debtor = new Debtor();
        $debtor->setUser($this->user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Recalc Debtor SRL');
        $debtor->setAddress('Str. Memorandumului 1, Cluj-Napoca');
        $debtor->setAddressCounty($county);
        $debtor->setAddressLocality($locality);
        $debtor->setCui('RO87654321');
        $this->em->persist($debtor);

        $link = new LegalCaseDebtor($debtor);
        $link->setAnafStatus(AnafStatus::ACTIV);
        $link->setAnafCheckedAt(new \DateTimeImmutable());
        $link->setInsolvencyCheckedAt(new \DateTimeImmutable());

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus(CaseStatus::AMIABIL);
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        $case->setAmount('0.00');
        $case->setCurrency('RON');
        $case->addDebtor($link);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function position(LegalCase $case, string $number, string $amount, string $dueDate, ?string $cause = null): ClaimItem
    {
        $item = new ClaimItem();
        $item->setDocumentNumber($number);
        $item->setDocumentDate(new \DateTimeImmutable($dueDate . ' -30 days'));
        $item->setAmount($amount);
        $item->setCurrency('RON');
        $item->setAmountRon($amount);
        $item->setDueDate(new \DateTimeImmutable($dueDate));
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('inv:' . strtolower($number));
        $item->setCauseReference($cause);
        $item->setDebtor($case->getPrimaryDebtor());
        $case->addClaimItem($item);
        $this->em->persist($item);

        return $item;
    }

    /** A position invoiced in euro and already converted at a stored rate. */
    private function euroPosition(LegalCase $case, string $number, string $amount, string $amountRon, string $dueDate): ClaimItem
    {
        $item = $this->position($case, $number, $amount, $dueDate);
        $item->setCurrency('EUR');
        $item->setAmountRon($amountRon);
        $item->setExchangeRate('4.9750');
        $item->setExchangeRateDate(new \DateTimeImmutable('-130 days'));

        return $item;
    }

    private function manualPrescriptionDeadline(LegalCase $case, string $date): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setType(DeadlineType::PRESCRIPTIE);
        $deadline->setDeadlineDate(new \DateTimeImmutable($date));
        $deadline->setPriority(DeadlinePriority::CRITICAL);
        $case->addDeadline($deadline);
        $this->em->persist($deadline);
        $this->em->flush();

        return $deadline;
    }

    /** @return array<string, mixed> */
    private function summonsContext(LegalCase $case): array
    {
        return static::getContainer()->get(SummonsContextBuilder::class)->build($case);
    }

    private function court(string $name): Court
    {
        $court = $this->em->getRepository(Court::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Court::class, $court, 'Court fixture missing: ' . $name);

        return $court;
    }

    private function termEnd(string $dueDate): string
    {
        $term = $this->deadlineService->limitationTermEnd(DeadlineType::PRESCRIPTIE, new \DateTimeImmutable($dueDate));
        self::assertNotNull($term);

        return $term->end->format('Y-m-d');
    }

    /** @return list<LegalDeadline> */
    private function prescriptionDeadlines(LegalCase $case): array
    {
        return $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $case, 'type' => DeadlineType::PRESCRIPTIE]);
    }

    /**
     * @param list<LegalDeadline> $deadlines
     * @return list<string> ascending Y-m-d
     */
    private function dates(array $deadlines): array
    {
        $dates = array_map(static fn (LegalDeadline $d): string => $d->getDeadlineDate()->format('Y-m-d'), $deadlines);
        sort($dates);

        return $dates;
    }

    private function findPrescriptionDeadline(LegalCase $case, string $date): ?LegalDeadline
    {
        foreach ($this->prescriptionDeadlines($case) as $deadline) {
            if ($deadline->getDeadlineDate()->format('Y-m-d') === $date) {
                return $deadline;
            }
        }

        return null;
    }

    /**
     * @param list<AdmissibilityIssue> $issues
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (AdmissibilityIssue $i): string => $i->code, $issues);
    }

    /** @param list<AdmissibilityIssue> $issues */
    private function issue(array $issues, string $code): AdmissibilityIssue
    {
        foreach ($issues as $issue) {
            if ($issue->code === $code) {
                return $issue;
            }
        }
        self::fail('Issue not reported: ' . $code);
    }
}
