<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Case\ClaimDetailsInput;
use App\DTO\Case\ClaimPositionInput;
use App\Entity\AuditLog;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\CaseStatus;
use App\Enum\ClaimExclusionReason;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
use App\Service\Case\ClaimEditRefused;
use App\Service\Case\ClaimItemFactory;
use App\Service\Case\ClaimPositionEditor;
use App\Service\Case\ClaimRecalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the courts and the NBR rates actually stored, like the
 * recalculator it drives.
 */
final class ClaimPositionEditorTest extends KernelTestCase
{
    private const CREDITOR_CUI = 'RO12345678';

    private EntityManagerInterface $em;
    private ClaimPositionEditor $editor;
    private ClaimItemFactory $factory;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->editor = $container->get(ClaimPositionEditor::class);
        $this->factory = $container->get(ClaimItemFactory::class);

        $this->user = new User();
        $this->user->setEmail('claim-editor-' . uniqid() . '@test.com');
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
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM debtor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    public function testAddManualWritesThePositionTheTotalsAndTheAudit(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        $result = $this->editor->addManual($case, $this->input('F-2', 500.0, '-50 days'), $this->user);

        self::assertSame('1500.00', $case->getAmount());
        self::assertSame(2, $result->totals->countedCount);
        // Neither due date had a limitation term yet: the fixture writes positions
        // without one, so the first edit creates both.
        self::assertCount(2, $result->deadlinesCreated);
        $items = $case->getClaimItems()->toArray();
        self::assertCount(2, $items);
        $added = $items[1];
        self::assertSame('F-2', $added->getDocumentNumber());
        self::assertTrue($added->isConfirmedByLawyer());
        self::assertSame($case->getPrimaryDebtor()?->getId(), $added->getDebtor()?->getId());

        $entry = $this->lastClaimAudit($case);
        self::assertSame('add_manual', $entry->getNewData()['reason']);
        self::assertSame('manual', $entry->getOldData()['input']['source']['origin']);
        self::assertSame('F-2', $entry->getOldData()['input']['documentNumber']);
        self::assertCount(1, $entry->getOldData()['items'], 'The snapshot before the edit holds one position.');
        self::assertCount(2, $entry->getNewData()['items']);
    }

    public function testTheSameInvoiceNumberIsRefusedInsteadOfSuffixed(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'FF 0012/2025', '1000.00', '-100 days');
        $this->position($case, 'F-9', '1000.00', '-90 days');
        $this->em->flush();

        try {
            $this->editor->addManual($case, $this->input('FF12/2025', 700.0, '-10 days'), $this->user);
            self::fail('A repeated invoice number must be refused.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('duplicate', $refusal->reasonCode);
            self::assertSame('FF 0012/2025', $refusal->params['%position%']);
            self::assertSame('FF 0012/2025', $refusal->existingItem?->getDocumentNumber());
        }

        $this->em->clear();
        self::assertSame(2, $this->em->getRepository(ClaimItem::class)->count(['legalCase' => $case->getId()]));
    }

    public function testWithoutANumberTheSameDateAndSumCollideToo(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $weak = $this->position($case, null, '250.00', '-40 days');
        $weak->setDedupKey($this->factory->dedupKey(null, $weak->getDocumentDate(), 250.0, 'RON', self::CREDITOR_CUI));
        $this->em->flush();

        $input = $this->input(null, 250.0, '-40 days');
        $input->documentDate = $weak->getDocumentDate();

        $this->expectException(ClaimEditRefused::class);
        $this->expectExceptionMessage('duplicate');
        $this->editor->addManual($case, $input, $this->user);
    }

    public function testAnExcludedTwinIsOfferedForReinstatement(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $excluded = $this->position($case, 'F-2', '300.00', '-60 days');
        $excluded->setExcludedByLawyer(true);
        $this->em->flush();

        try {
            $this->editor->addManual($case, $this->input('F-2', 300.0, '-60 days'), $this->user);
            self::fail('The excluded twin must be refused with an offer to reinstate it.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('duplicate_excluded', $refusal->reasonCode);
            self::assertSame($excluded->getId(), $refusal->existingItem?->getId());
        }
    }

    public function testAForeignCurrencyWithoutARateIsRefused(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        $input = $this->input('F-EUR', 100.0, '2019-03-01');
        $input->currency = 'EUR';
        $input->documentDate = new \DateTimeImmutable('2019-02-01');

        try {
            $this->editor->addManual($case, $input, $this->user);
            self::fail('A position that could not be converted must not be saved.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('fx_missing', $refusal->reasonCode);
            self::assertSame('EUR', $refusal->params['%currency%']);
        }

        self::assertSame('1000.00', $case->getAmount());
        self::assertCount(1, $case->getClaimItems());
    }

    public function testACaseWithNoCountingPositionCannotBeEdited(): void
    {
        $case = $this->makeCase();
        $stuck = $this->position($case, 'F-EUR', '100.00', '-100 days');
        $stuck->setCurrency('EUR');
        $stuck->setAmountRon(null);
        $stuck->setNeedsManualFx(true);
        $this->em->flush();

        try {
            $this->editor->addManual($case, $this->input('F-2', 500.0, '-50 days'), $this->user);
            self::fail('Nothing to start from: the edit must be refused.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('no_counting_position', $refusal->reasonCode);
        }
        try {
            $this->editor->exclude($case, $stuck, ClaimExclusionReason::OTHER, null, $this->user);
            self::fail('Nothing to start from: the edit must be refused.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('no_counting_position', $refusal->reasonCode);
        }
    }

    public function testTheLastCountingPositionCannotBeExcluded(): void
    {
        $case = $this->makeCase();
        $only = $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        try {
            $this->editor->exclude($case, $only, ClaimExclusionReason::PAID_IN_FULL, 'paid on 01.02.2026', $this->user);
            self::fail('A case without a claim is deleted, not emptied.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('last_counting_position', $refusal->reasonCode);
        }

        self::assertFalse($only->isExcludedByLawyer());
        self::assertSame('1000.00', $case->getAmount());
    }

    public function testExcludeKeepsTheReasonAndReincludeClearsIt(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $second = $this->position($case, 'F-2', '500.00', '-60 days');
        $this->em->flush();

        $result = $this->editor->exclude($case, $second, ClaimExclusionReason::PAID_IN_FULL, 'paid on 20.06.2025', $this->user);

        self::assertTrue($second->isExcludedByLawyer());
        self::assertSame(ClaimExclusionReason::PAID_IN_FULL, $second->getExclusionReason());
        self::assertSame('paid on 20.06.2025', $second->getExclusionNote());
        self::assertSame('1000.00', $case->getAmount());
        self::assertSame(1, $result->totals->countedCount);
        self::assertSame('paid_in_full', $this->lastClaimAudit($case)->getNewData()['items'][1]['exclusionReason']);

        $this->editor->reinclude($case, $second, $this->user);

        self::assertFalse($second->isExcludedByLawyer());
        self::assertNull($second->getExclusionReason());
        self::assertNull($second->getExclusionNote());
        self::assertSame('1500.00', $case->getAmount());
        self::assertSame('reinclude', $this->lastClaimAudit($case)->getNewData()['reason']);
    }

    public function testExcludeTwiceOrReincludeACountingPositionIsRefused(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $second = $this->position($case, 'F-2', '500.00', '-60 days');
        $this->em->flush();

        try {
            $this->editor->reinclude($case, $second, $this->user);
            self::fail();
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('not_excluded', $refusal->reasonCode);
        }
        $this->editor->exclude($case, $second, ClaimExclusionReason::DISPUTED, null, $this->user);
        try {
            $this->editor->exclude($case, $second, ClaimExclusionReason::DISPUTED, null, $this->user);
            self::fail();
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('already_excluded', $refusal->reasonCode);
        }
    }

    public function testUpdateDetailsRewritesTheFieldsAndComparesTheCourtAgain(): void
    {
        $case = $this->makeCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $case->setContractualPenaltyRate('0.100');
        $case->setContractReference('Contract 1/2024');
        $local = $this->court('Judecătoria Cluj-Napoca');
        $case->setCourt($local);
        $this->position($case, 'F-1', '250000.00', '-100 days', 'Contract 1/2024');
        $this->em->flush();

        // The dialog starts from the case, so the contractual mode stays as it is.
        $input = ClaimDetailsInput::fromCase($case);
        $input->claimDescription = '  Lucrări de construcție  ';
        $input->contractReference = 'Contract 2/2024';
        $input->penaltyClauseArticle = 'art. 9.2';
        $input->penaltyClauseText = 'Penalități de 0,1% pe zi.';
        $input->paymentNoticeNumber = '114/2026';
        $result = $this->editor->updateDetails($case, $input, $this->user);

        self::assertSame('Lucrări de construcție', $case->getClaimDescription());
        self::assertSame('Contract 2/2024', $case->getContractReference());
        self::assertSame('art. 9.2', $case->getPenaltyClauseArticle());
        self::assertSame('114/2026', $case->getPaymentNoticeNumber());
        self::assertSame('250000.00', $case->getAmount(), 'The details never touch the sums.');
        $proposal = $result->courtProposal;
        self::assertNotNull($proposal, 'The rewrite runs the competence rules again.');
        self::assertTrue($proposal->isChange());
        self::assertSame($local->getId(), $proposal->current?->getId());
        self::assertSame('Tribunalul Specializat Cluj', $proposal->proposed?->getName());
        self::assertSame($local->getId(), $case->getCourt()?->getId(), 'Proposed, never written.');
        $entry = $this->lastClaimAudit($case);
        self::assertSame('edit_details', $entry->getNewData()['reason']);
        self::assertSame('Contract 1/2024', $entry->getOldData()['details']['contractReference']);
    }

    public function testAStatutoryCaseKeepsNoClauseWhateverWasTyped(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        $this->editor->updateDetails($case, new ClaimDetailsInput(penaltyClauseArticle: 'art. 9', penaltyClauseText: 'text'), $this->user);

        self::assertNull($case->getPenaltyClauseArticle());
        self::assertNull($case->getPenaltyClauseText());
    }

    public function testSwitchingToContractualRecomputesTheAccessoryAndKeepsTheClause(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '10000.00', '-100 days');
        $this->em->flush();
        $this->editor->updateDetails($case, ClaimDetailsInput::fromCase($case), $this->user);
        $statutory = $case->getCalculatedInterest();
        self::assertNotNull($statutory);

        $input = ClaimDetailsInput::fromCase($case);
        $input->penaltyType = PenaltyType::CONTRACTUAL;
        $input->contractualPenaltyRate = 0.1;
        $input->contractualPenaltyCapPercent = 5.0;
        $input->contractualAccessoryLabel = ContractualAccessoryLabel::DOBANZI_PENALIZATOARE;
        $input->penaltyClauseArticle = 'art. 7';
        $result = $this->editor->updateDetails($case, $input, $this->user);

        self::assertSame(PenaltyType::CONTRACTUAL, $case->getPenaltyType());
        self::assertSame('0.100', $case->getContractualPenaltyRate());
        self::assertSame('5.00', $case->getContractualPenaltyCapPercent());
        self::assertSame(ContractualAccessoryLabel::DOBANZI_PENALIZATOARE, $case->getContractualAccessoryLabel());
        self::assertSame('art. 7', $case->getPenaltyClauseArticle());
        // 0.1% a day over 100 days is 10% of the sum, held at the 5% cap.
        self::assertSame('500.00', $case->getCalculatedInterest());
        self::assertNotSame($statutory, $case->getCalculatedInterest());
        self::assertNotNull($result->accessoryChange);
        self::assertSame(['from' => 'LEGAL_PENALIZATOARE', 'to' => 'CONTRACTUAL'], $result->detailsChange['penaltyType']);
        self::assertArrayNotHasKey('claimDescription', $result->detailsChange, 'Only the fields that moved are listed.');
        $entry = $this->lastClaimAudit($case);
        self::assertSame('LEGAL_PENALIZATOARE', $entry->getOldData()['details']['penaltyType']);
        self::assertSame('CONTRACTUAL', $entry->getNewData()['details']['penaltyType']);
        self::assertSame('CONTRACTUAL', $entry->getNewData()['detailsChange']['penaltyType']['to']);
    }

    public function testSwitchingToStatutoryClearsClauseCapAndLabel(): void
    {
        $case = $this->makeCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $case->setContractualPenaltyRate('0.100');
        $case->setContractualPenaltyCapPercent('10.00');
        $case->setContractualAccessoryLabel(ContractualAccessoryLabel::MAJORARI_INTARZIERE);
        $case->setPenaltyClauseArticle('art. 9');
        $case->setPenaltyClauseText('Penalități de 0,1% pe zi.');
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        $input = ClaimDetailsInput::fromCase($case);
        $input->penaltyType = PenaltyType::LEGAL_PENALIZATOARE;
        $result = $this->editor->updateDetails($case, $input, $this->user);

        self::assertSame(PenaltyType::LEGAL_PENALIZATOARE, $case->getPenaltyType());
        self::assertNull($case->getPenaltyClauseArticle());
        self::assertNull($case->getPenaltyClauseText());
        self::assertNull($case->getContractualPenaltyCapPercent());
        self::assertNull($case->getContractualAccessoryLabel());
        self::assertSame('0.100', $case->getContractualPenaltyRate(), 'The rate is kept as typed, like the wizard does; the statutory mode never reads it.');
        self::assertArrayHasKey('penaltyClauseArticle', $result->detailsChange);
        self::assertSame('art. 9', $result->detailsChange['penaltyClauseArticle']['from']);
    }

    /** OG 13/2011: + 4 pp for a contract concluded before 05.04.2013, + 8 pp after. */
    public function testAnEarlierContractDateChangesTheStatutoryMargin(): void
    {
        $case = $this->makeCase();
        $case->setContractDate(new \DateTime('2020-01-01'));
        $this->position($case, 'F-1', '100000.00', '-100 days');
        $this->em->flush();
        $this->editor->updateDetails($case, ClaimDetailsInput::fromCase($case), $this->user);
        $recent = (float) $case->getCalculatedInterest();

        $input = ClaimDetailsInput::fromCase($case);
        $input->contractDate = new \DateTimeImmutable('2012-01-01');
        $result = $this->editor->updateDetails($case, $input, $this->user);

        self::assertSame('2012-01-01', $case->getContractDate()?->format('Y-m-d'));
        self::assertLessThan($recent, (float) $case->getCalculatedInterest());
        self::assertNotNull($result->accessoryChange);
        self::assertSame(['from' => '2020-01-01', 'to' => '2012-01-01'], $result->detailsChange['contractDate']);
    }

    public function testACutoffDateLimitsTheAccessoryAndWarnsForLaterPositions(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '10000.00', '-100 days');
        $this->position($case, 'F-2', '5000.00', '-20 days');
        $this->em->flush();
        $this->editor->updateDetails($case, ClaimDetailsInput::fromCase($case), $this->user);
        $uncut = (float) $case->getCalculatedInterest();

        $cutoff = new \DateTimeImmutable('-50 days midnight');
        $input = ClaimDetailsInput::fromCase($case);
        $input->accessoryCutoffDate = $cutoff;
        $result = $this->editor->updateDetails($case, $input, $this->user);

        self::assertSame($cutoff->format('Y-m-d'), $case->getAccessoryCutoffDate()?->format('Y-m-d'));
        self::assertSame($cutoff->format('Y-m-d'), $result->referenceDate->format('Y-m-d'));
        self::assertLessThan($uncut, (float) $case->getCalculatedInterest());
        $codes = array_map(static fn ($issue): string => $issue->code, $result->issues['warnings']);
        self::assertContains(ClaimRecalculator::CODE_CUTOFF_BEFORE_DUE_DATE, $codes);
    }

    /**
     * Competence follows the principal of each cause, accessories aside: a large
     * contractual accessory is compared again and still names the same court.
     */
    public function testAContractualAccessoryDoesNotMoveTheCourt(): void
    {
        $case = $this->makeCase();
        $local = $this->court('Judecătoria Cluj-Napoca');
        $case->setCourt($local);
        $this->position($case, 'F-1', '190000.00', '-100 days');
        $this->em->flush();

        $input = ClaimDetailsInput::fromCase($case);
        $input->penaltyType = PenaltyType::CONTRACTUAL;
        $input->contractualPenaltyRate = 0.5;
        $result = $this->editor->updateDetails($case, $input, $this->user);

        self::assertGreaterThan(200000.0, (float) $case->getAmount() + (float) $case->getCalculatedInterest(), 'Principal and accessory together pass the threshold.');
        self::assertNull($result->courtProposal, 'The rules still name the local court.');
        self::assertSame($local->getId(), $case->getCourt()?->getId());
    }

    public function testAnAccessoryEditMarksTheGeneratedSummonsOutdated(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $summons = $this->invoice($case, []);
        $summons->setDocumentType(DocumentType::SOMATIE);
        $this->em->flush();

        $input = ClaimDetailsInput::fromCase($case);
        $input->penaltyType = PenaltyType::CONTRACTUAL;
        $input->contractualPenaltyRate = 0.1;
        $this->editor->updateDetails($case, $input, $this->user);

        self::assertTrue($summons->isOutdated());
    }

    public function testTheSamePostTwiceAddsOnce(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        $this->editor->addManual($case, $this->input('F-2', 500.0, '-50 days'), $this->user);
        try {
            $this->editor->addManual($case, $this->input('F-2', 500.0, '-50 days'), $this->user);
            self::fail('The second identical post must be refused.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('duplicate', $refusal->reasonCode);
        }

        self::assertCount(2, $case->getClaimItems());
        self::assertSame('1500.00', $case->getAmount());
    }

    public function testPastTheAmiableStageEveryEditIsRefused(): void
    {
        $case = $this->makeCase();
        $first = $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->position($case, 'F-2', '500.00', '-60 days');
        $case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        foreach ([
            fn () => $this->editor->addManual($case, $this->input('F-3', 1.0, '-1 day'), $this->user),
            fn () => $this->editor->exclude($case, $first, ClaimExclusionReason::OTHER, null, $this->user),
            fn () => $this->editor->updateDetails($case, new ClaimDetailsInput(claimDescription: 'x'), $this->user),
        ] as $edit) {
            try {
                $edit();
                self::fail('An edit after the summons must be refused.');
            } catch (ClaimEditRefused $refusal) {
                self::assertSame('status_changed', $refusal->reasonCode);
            }
        }
        self::assertSame('1500.00', $case->getAmount());
    }

    public function testAnInvoiceFromAnotherContractNeedsTheLawyersConfirmation(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days', 'Contract 7/2024');
        $this->em->flush();

        $input = $this->input('F-2', 500.0, '-50 days');
        $input->causeReference = 'CTR 88/2025';
        try {
            $this->editor->addManual($case, $input, $this->user);
            self::fail('Another cause is the lawyer\'s call.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('other_contract', $refusal->reasonCode);
            self::assertSame('CTR 88/2025', $refusal->params['%cause%']);
            self::assertSame('Contract 7/2024', $refusal->params['%existing%']);
        }
        self::assertCount(1, $case->getClaimItems());

        $input->sameCauseConfirmed = true;
        $result = $this->editor->addManual($case, $input, $this->user);

        self::assertCount(2, $case->getClaimItems());
        self::assertTrue($this->lastClaimAudit($case)->getOldData()['input']['sameCauseConfirmed']);
        self::assertSame('1500.00', $case->getAmount());
        self::assertNotNull($result);
    }

    public function testAnInvoiceFromAnotherContractThanTheCaseContractNeedsConfirmationWhenNoPositionHasACause(): void
    {
        $case = $this->makeCase();
        $case->setContractReference('Contract 7/2024');
        $this->position($case, 'F-1', '1000.00', '-100 days', null);
        $this->em->flush();

        $input = $this->input('F-2', 500.0, '-50 days');
        $input->causeReference = 'CTR 88/2025';
        try {
            $this->editor->addManual($case, $input, $this->user);
            self::fail('Another contract is the lawyer\'s call.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('other_contract', $refusal->reasonCode);
            self::assertSame('Contract 7/2024', $refusal->params['%existing%']);
        }
    }

    public function testAContractualCaseAlsoAsksWhetherTheClauseApplies(): void
    {
        $case = $this->makeCase();
        $case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $case->setContractualPenaltyRate('0.100');
        $this->position($case, 'F-1', '1000.00', '-100 days', 'Contract 7/2024');
        $this->em->flush();

        $input = $this->input('F-2', 500.0, '-50 days');
        $input->causeReference = 'CTR 88/2025';
        $input->sameCauseConfirmed = true;
        try {
            $this->editor->addManual($case, $input, $this->user);
            self::fail('The clause of the case does not cover another contract on its own.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('clause_unconfirmed', $refusal->reasonCode);
        }

        $input->clauseAppliesConfirmed = true;
        $this->editor->addManual($case, $input, $this->user);
        self::assertCount(2, $case->getClaimItems());
    }

    public function testASecondDebtorHasToBeNamed(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $other = new Debtor();
        $other->setUser($this->user);
        $other->setPersonType(PersonType::PJ);
        $other->setName('SC Second Debtor SRL');
        $other->setAddress('Str. A 2, Cluj-Napoca');
        $other->setCui('RO55556666');
        $this->em->persist($other);
        $link = new LegalCaseDebtor($other);
        $case->addDebtor($link);
        $this->em->flush();

        try {
            $this->editor->addManual($case, $this->input('F-2', 500.0, '-50 days'), $this->user);
            self::fail();
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('debtor_required', $refusal->reasonCode);
        }

        $input = $this->input('F-2', 500.0, '-50 days');
        $input->debtorId = $link->getId();
        $this->editor->addManual($case, $input, $this->user);
        self::assertSame($link->getId(), $case->getClaimItems()->last()->getDebtor()?->getId());
    }

    public function testAcceptCourtWritesOnlyTheCourtTheRulesPropose(): void
    {
        $case = $this->makeCase();
        $local = $this->court('Judecătoria Cluj-Napoca');
        $case->setCourt($local);
        $this->position($case, 'F-1', '150000.00', '-100 days', 'Contract 7/2024');
        $this->position($case, 'F-2', '100000.00', '-50 days', 'Contract 7/2024');
        $this->em->flush();

        try {
            $this->editor->acceptCourt($case, $local->getId(), $this->user);
            self::fail('The current court is not a proposal.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('court_proposal_stale', $refusal->reasonCode);
        }
        self::assertSame($local->getId(), $case->getCourt()?->getId());

        $tribunal = $this->court('Tribunalul Specializat Cluj');
        $accepted = $this->editor->acceptCourt($case, $tribunal->getId(), $this->user);

        self::assertSame($tribunal->getId(), $accepted->getId());
        self::assertSame($tribunal->getId(), $case->getCourt()?->getId());
        $entry = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'court_accepted', 'entityId' => (string) $case->getId()]);
        self::assertInstanceOf(AuditLog::class, $entry);
        self::assertSame($local->getId(), $entry->getOldData()['court_id']);
        self::assertSame($tribunal->getId(), $entry->getNewData()['court_id']);
    }

    public function testAnEditMarksTheSummonsGeneratedInTheAmiableStageAsOutdated(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $second = $this->position($case, 'F-2', '500.00', '-60 days');
        $case->setPaymentNoticeDate(new \DateTime('-2 days'));
        $summons = new Document();
        $summons->setLegalCase($case);
        $summons->setDocumentType(DocumentType::SOMATIE);
        $summons->setOriginalFilename('somatie.pdf');
        $summons->setStoredFilename('cases/x/somatie.pdf');
        $summons->setFileSize(10);
        $summons->setMimeType('application/pdf');
        $summons->setUploadedBy($this->user);
        $case->addDocument($summons);
        $this->em->persist($summons);
        $this->em->flush();
        self::assertFalse($summons->isOutdated());

        $this->editor->exclude($case, $second, ClaimExclusionReason::OTHER, null, $this->user);

        self::assertTrue($summons->isOutdated());
    }

    /**
     * The summons can be generated from another tab between the page load and
     * the post: the status in memory still says amiable, the row does not.
     * The check inside the lock is the one that counts.
     */
    public function testAStatusChangedUnderneathIsCaughtInsideTheLock(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE legal_case SET status = :status WHERE id = :id',
            ['status' => CaseStatus::SOMATIE_TRIMISA->value, 'id' => $case->getId()],
        );
        self::assertSame(CaseStatus::AMIABIL, $case->getStatus(), 'The entity in memory still believes the case is amiable.');

        try {
            $this->editor->addManual($case, $this->input('F-2', 500.0, '-50 days'), $this->user);
            self::fail('The status read under the lock must refuse the edit.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('status_changed', $refusal->reasonCode);
        }

        self::assertSame(CaseStatus::SOMATIE_TRIMISA, $case->getStatus(), 'The refusal leaves the entity as the row is.');
        self::assertTrue($this->em->isOpen(), 'A refusal keeps the manager usable.');
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(ClaimItem::class)->count(['legalCase' => $case->getId()]));
        self::assertSame('1000.00', $this->em->find(LegalCase::class, $case->getId())->getAmount());
    }

    public function testReincludingAPositionWithoutARateIsRefused(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $stuck = $this->position($case, 'F-EUR', '100.00', '-60 days');
        $stuck->setCurrency('EUR');
        $stuck->setAmountRon(null);
        $stuck->setNeedsManualFx(true);
        $stuck->setExcludedByLawyer(true);
        $stuck->setExclusionReason(ClaimExclusionReason::OTHER);
        $case->setAmount('1000.00');
        $this->em->flush();

        try {
            $this->editor->reinclude($case, $stuck, $this->user);
            self::fail('A position with no RON value cannot come back into the total.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('fx_missing', $refusal->reasonCode);
            self::assertSame('EUR', $refusal->params['%currency%']);
            self::assertSame($stuck->getDocumentDate()?->format('d.m.Y'), $refusal->params['%date%']);
        }

        self::assertTrue($stuck->isExcludedByLawyer());
        self::assertSame(ClaimExclusionReason::OTHER, $stuck->getExclusionReason());
        self::assertSame('1000.00', $case->getAmount());
    }

    public function testAnInvoiceOfAnotherCaseIsRefused(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $other = $this->makeCase(sameParties: $case);
        $document = $this->invoice($other, [
            'creditor' => ['cui' => self::CREDITOR_CUI],
            'debtor' => ['cui' => 'RO87654321'],
            'claim' => ['invoiceNumber' => 'X-1', 'amount' => 100, 'currency' => 'RON'],
        ]);
        $this->em->flush();

        try {
            $this->editor->addFromDocument($case, $document, $this->input('X-1', 100.0, '-10 days'), $this->user);
            self::fail('A document of another case is not evidence for this one.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('document_mismatch', $refusal->reasonCode);
        }

        self::assertCount(1, $case->getClaimItems());
        self::assertInstanceOf(Document::class, $this->em->find(Document::class, $document->getId()), 'The other case keeps its file.');
    }

    public function testAnInvoiceBetweenOtherPartiesIsRefusedAndTheFileRemoved(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $document = $this->invoice($case, [
            'creditor' => ['cui' => 'RO11223344', 'name' => 'SC Gamma Trade SRL'],
            'debtor' => ['cui' => 'RO87654321', 'name' => 'SC Editor Debtor SRL'],
            'claim' => ['invoiceNumber' => 'G-1', 'amount' => 100, 'currency' => 'RON'],
        ]);
        $this->em->flush();
        $documentId = $document->getId();

        try {
            $this->editor->addFromDocument($case, $document, $this->input('G-1', 100.0, '-10 days'), $this->user);
            self::fail('An invoice issued by another company belongs in another case.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('party_mismatch', $refusal->reasonCode);
            self::assertSame('SC Gamma Trade SRL', $refusal->params['%creditor%']);
            self::assertSame('SC Editor Debtor SRL', $refusal->params['%debtor%']);
        }

        $this->em->clear();
        self::assertNull($this->em->find(Document::class, $documentId), 'The file was stored only to be read; the refusal removes it.');
        self::assertSame(1, $this->em->getRepository(ClaimItem::class)->count(['legalCase' => $case->getId()]));
        $deletion = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'document_delete', 'entityId' => (string) $documentId]);
        self::assertInstanceOf(AuditLog::class, $deletion);
        self::assertSame($this->user->getId(), $deletion->getUser()?->getId());
    }

    /**
     * The lawyer corrects what the model read before saving; the entry keeps
     * both, so a corrected field can be told from one taken as read.
     */
    public function testAddFromDocumentKeepsWhatWasReadNextToWhatWasSaved(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $document = $this->invoice($case, [
            'creditor' => ['cui' => 'RO 12345678', 'name' => 'SC Editor Creditor SRL'],
            'debtor' => ['cui' => '87654321', 'name' => 'SC Editor Debtor SRL'],
            'claim' => ['invoiceNumber' => 'F-2025-0210', 'invoiceDate' => '2025-05-20', 'dueDate' => '2025-06-14', 'amount' => 12500, 'currency' => 'RON'],
        ]);
        $this->em->flush();

        $input = new ClaimPositionInput(
            documentNumber: 'F-2025-0210',
            documentDate: new \DateTimeImmutable('2025-05-20'),
            dueDate: new \DateTimeImmutable('2025-06-30'),
            amount: 12000.0,
            currency: 'RON',
        );
        $result = $this->editor->addFromDocument($case, $document, $input, $this->user);

        self::assertSame($document->getId(), $input->sourceDocumentId);
        self::assertSame('13000.00', $case->getAmount());
        self::assertSame(2, $result->totals->countedCount);
        $added = $case->getClaimItems()->last();
        self::assertSame('F-2025-0210', $added->getDocumentNumber());
        self::assertSame($document->getId(), $added->getSourceDocument()?->getId());
        self::assertSame('2025-06-30', $added->getDueDate()?->format('Y-m-d'));
        self::assertInstanceOf(Document::class, $this->em->find(Document::class, $document->getId()), 'A matching invoice stays on file.');

        $entry = $this->lastClaimAudit($case);
        self::assertSame('add_from_document', $entry->getNewData()['reason']);
        $source = $entry->getOldData()['input']['source'];
        self::assertSame('document', $source['origin']);
        self::assertSame($document->getId(), $source['documentId']);
        self::assertSame('completed', strtolower((string) $source['extractionStatus']));
        self::assertSame('2025-06-14', $source['extractedClaim']['dueDate'], 'What the model read.');
        self::assertSame(12500, $source['extractedClaim']['amount']);
        self::assertSame('2025-06-30', $entry->getOldData()['input']['dueDate'], 'What the lawyer saved.');
        self::assertSame(12000.0, $entry->getOldData()['input']['amount']);
    }

    /**
     * A due date still to come is legitimate before the summons: the position is
     * saved, the result names the error that will block the summons, the audit
     * entry records it.
     */
    public function testAFutureDueDateIsSavedAndReportedAsAnIssue(): void
    {
        $case = $this->makeCase();
        $this->position($case, 'F-1', '1000.00', '-100 days');
        $this->em->flush();

        $result = $this->editor->addManual($case, $this->input('F-LATER', 200.0, '+10 days'), $this->user);

        self::assertCount(2, $case->getClaimItems());
        self::assertSame('1200.00', $case->getAmount());
        self::assertTrue($result->hasErrors());
        $codes = array_map(static fn ($issue): string => $issue->code, $result->issues['errors']);
        self::assertContains('OP_ITEM_NOT_YET_DUE', $codes);
        self::assertContains('OP_ITEM_NOT_YET_DUE', $this->lastClaimAudit($case)->getNewData()['issues']['errors']);
    }

    /** A second case of the same lawyer shares the parties (one creditor per code per user). */
    private function makeCase(?LegalCase $sameParties = null): LegalCase
    {
        if ($sameParties !== null) {
            $creditor = $sameParties->getCreditor();
            $debtor = $sameParties->getPrimaryDebtor()->getDebtor();
        } else {
            $creditor = new Creditor();
            $creditor->setUser($this->user);
            $creditor->setPersonType(PersonType::PJ);
            $creditor->setName('SC Editor Creditor SRL');
            $creditor->setAddress('Str. Editor 1, Cluj-Napoca');
            $creditor->setCui(self::CREDITOR_CUI);
            $this->em->persist($creditor);

            $debtor = new Debtor();
            $debtor->setUser($this->user);
            $debtor->setPersonType(PersonType::PJ);
            $debtor->setName('SC Editor Debtor SRL');
            $debtor->setAddress('Str. Memorandumului 1, Cluj-Napoca');
            $debtor->setAddressCounty('Cluj');
            $debtor->setAddressLocality('Cluj-Napoca');
            $debtor->setCui('RO87654321');
            $this->em->persist($debtor);
        }

        $link = new LegalCaseDebtor($debtor);
        $link->setAnafStatus(AnafStatus::ACTIV);
        $link->setAnafCheckedAt(new \DateTimeImmutable());
        $link->setInsolvencyCheckedAt(new \DateTimeImmutable());

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($creditor);
        $case->setStatus(CaseStatus::AMIABIL);
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        $case->setAmount('0.00');
        $case->setCurrency('RON');
        $case->addDebtor($link);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function position(LegalCase $case, ?string $number, string $amount, string $dueDate, ?string $cause = null): ClaimItem
    {
        $due = new \DateTimeImmutable($dueDate);
        $item = new ClaimItem();
        $item->setDocumentNumber($number);
        $item->setDocumentDate($due->modify('-30 days'));
        $item->setAmount($amount);
        $item->setCurrency('RON');
        $item->setAmountRon($amount);
        $item->setDueDate($due);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey($this->factory->dedupKey($number, $due->modify('-30 days'), (float) $amount, 'RON', self::CREDITOR_CUI));
        $item->setCauseReference($cause);
        $item->setDebtor($case->getPrimaryDebtor());
        $case->addClaimItem($item);
        $this->em->persist($item);
        // Keep the stored total in step with the positions, as a saved case is.
        $total = 0.0;
        foreach ($case->getCountingClaimItems() as $counting) {
            $total += (float) $counting->getAmountRon();
        }
        $case->setAmount(sprintf('%.2f', $total));

        return $item;
    }

    private function input(?string $number, float $amount, string $dueDate): ClaimPositionInput
    {
        $due = new \DateTimeImmutable($dueDate);

        return new ClaimPositionInput(
            documentNumber: $number,
            documentDate: $due->modify('-30 days'),
            dueDate: $due,
            amount: $amount,
            currency: 'RON',
        );
    }

    /** @param array<string, mixed> $extracted */
    private function invoice(LegalCase $case, array $extracted): Document
    {
        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('factura-' . uniqid() . '.pdf');
        $document->setStoredFilename('cases/' . $case->getId() . '/' . uniqid() . '.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->user);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $document->setExtractedData($extracted);
        $document->setExtractionConfidence('0.90');
        $case->addDocument($document);
        $this->em->persist($document);

        return $document;
    }

    private function court(string $name): Court
    {
        $court = $this->em->getRepository(Court::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Court::class, $court, 'Court fixture missing: ' . $name);

        return $court;
    }

    private function lastClaimAudit(LegalCase $case): AuditLog
    {
        $entries = $this->em->getRepository(AuditLog::class)->findBy(
            ['category' => AuditLogService::CATEGORY_CLAIM_EDITED, 'entityId' => (string) $case->getId(), 'action' => 'claim_edited'],
            ['id' => 'DESC'],
            1,
        );
        self::assertCount(1, $entries);

        return $entries[0];
    }
}
