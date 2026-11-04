<?php

declare(strict_types=1);

namespace App\Tests\Service\Wizard;

use App\DTO\Calculation\CurrencyConversionResult;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\DTO\Wizard\WizardPersistResult;
use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\User;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Repository\CreditorRepository;
use App\Repository\DocumentRepository;
use App\Service\AuditLogService;
use App\Service\Case\CaseFxTrace;
use App\Service\Case\CaseStampDuty;
use App\Service\Case\ClaimItemFactory;
use App\Service\Case\ClaimTotalsService;
use App\Service\Case\LegalCaseClaimFieldsMapper;
use App\Service\Wizard\CaseWizardPersister;
use App\Service\Wizard\WizardBag;
use App\Service\Wizard\WizardCreditorResolver;
use App\Service\Wizard\WizardDebtorResolver;
use App\Service\Wizard\WizardFigures;
use App\Service\Wizard\WizardStateStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CaseWizardPersisterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseWizardPersister $persister;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->persister = $container->get(CaseWizardPersister::class);

        $this->user = new User();
        $this->user->setEmail('wizard-persister-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();

        $container->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()));
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

    public function testAFailureWhileAttachingTheDocumentsRollsBackTheCaseTheCreditorAndTheAudit(): void
    {
        $container = static::getContainer();
        $documents = $this->createStub(DocumentRepository::class);
        $documents->method('attachToCase')->willThrowException(new \RuntimeException('attach failed'));
        $persister = new CaseWizardPersister(
            $this->em,
            $container->get(WizardCreditorResolver::class),
            $container->get(WizardDebtorResolver::class),
            $container->get(WizardFigures::class),
            $container->get(LegalCaseClaimFieldsMapper::class),
            $container->get(ClaimItemFactory::class),
            $container->get(ClaimTotalsService::class),
            $container->get(CaseFxTrace::class),
            $container->get(CaseStampDuty::class),
            $container->get(CreditorRepository::class),
            $documents,
            $container->get(WizardStateStore::class),
            $container->get(AuditLogService::class),
        );
        $claim = $this->claim();

        try {
            $persister->persist(
                $this->session(),
                $this->user,
                $this->newCreditor(),
                $this->debtors(),
                $claim,
                [999999],
                $this->calculations(),
                ['auto' => [], 'manual' => []],
                [],
                null,
                'none',
                null,
                $persister->resolveClaimRows(new WizardBag(), $claim),
            );
            self::fail('The failed attach should have propagated.');
        } catch (\RuntimeException $e) {
            self::assertSame('attach failed', $e->getMessage());
        }

        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM legal_case WHERE user_id = :id', ['id' => $id]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM creditor WHERE user_id = :id', ['id' => $id]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM debtor WHERE user_id = :id', ['id' => $id]));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE user_id = :id AND action = 'wizard_submit'", ['id' => $id]));
    }

    public function testACreditorFromTheLibraryIsReportedAsReusedOnceCommitted(): void
    {
        $library = new Creditor();
        $library->setUser($this->user);
        $library->setPersonType(PersonType::PJ);
        $library->setName('Creditor Bibliotecă SRL');
        $library->setCui('RO12345678');
        $library->setAddress('Str. Veche 1');
        $this->em->persist($library);
        $this->em->flush();

        $dto = $this->newCreditor();
        $dto->creditorId = $library->getId();
        $claim = $this->claim();

        $result = $this->persister->persist(
            $this->session(),
            $this->user,
            $dto,
            $this->debtors(),
            $claim,
            [],
            $this->calculations(),
            ['auto' => [], 'manual' => ['claim.amount']],
            [],
            null,
            'none',
            null,
            $this->persister->resolveClaimRows(new WizardBag(), $claim),
        );

        self::assertTrue($result->creditorReused);
        self::assertSame('library', $result->creditorSource);
        self::assertNotNull($result->case->getId());
        self::assertSame($library->getId(), $result->case->getCreditor()?->getId());
        self::assertSame('1000.00', $result->case->getAmount());
        self::assertCount(1, $result->case->getDebtors());
        self::assertCount(1, $result->case->getClaimItems());

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'wizard_submit', 'entityId' => (string) $result->case->getId()]);
        self::assertNotNull($audit);
        self::assertSame(['creditorId' => $library->getId(), 'source' => 'library'], $audit->getNewData()['creditor']);
        self::assertSame(['claim.amount'], $audit->getNewData()['fields_manual']);
        self::assertSame('table', $audit->getNewData()['claim_items'][0]['confirmation']);
    }

    public function testATypedCreditorIsCreatedWithTheCase(): void
    {
        $claim = $this->claim();

        $result = $this->persister->persist(
            $this->session(),
            $this->user,
            $this->newCreditor(),
            $this->debtors(),
            $claim,
            [],
            $this->calculations(),
            ['auto' => [], 'manual' => []],
            [],
            null,
            'none',
            null,
            $this->persister->resolveClaimRows(new WizardBag(), $claim),
        );

        self::assertFalse($result->creditorReused);
        self::assertSame('new', $result->creditorSource);
        self::assertNotNull($result->case->getCreditor()?->getId());
        self::assertSame('Creditor Nou SRL', $result->case->getCreditor()->getName());
        self::assertSame('new', $this->em->getRepository(AuditLog::class)
            ->findOneBy(['action' => 'wizard_submit', 'entityId' => (string) $result->case->getId()])
            ?->getNewData()['debtors'][0]['source']);
    }

    public function testTheRowsOfTheBagStandAndAManualClaimBecomesOneConfirmedRow(): void
    {
        $claim = $this->claim();
        $fromClaim = $this->persister->resolveClaimRows(new WizardBag(), $claim);

        self::assertCount(1, $fromClaim);
        self::assertTrue($fromClaim[0]->confirmed);
        self::assertSame(1000.0, $fromClaim[0]->amount);

        $bagRows = $this->persister->resolveClaimRows(new WizardBag(claimItems: $fromClaim), new Step3ClaimData());
        self::assertSame($fromClaim, $bagRows);
        self::assertSame([], $this->persister->resolveClaimRows(new WizardBag(), new Step3ClaimData()));
    }

    public function testTheSkeletonCarriesTheDebtorsAndThePositionsWithoutBeingPersisted(): void
    {
        $claim = $this->claim();
        $debtors = $this->debtors();
        $debtors->debtors[] = new Step2DebtorEntry();

        $skeleton = $this->persister->buildLegalCaseSkeleton(
            $this->user,
            $this->newCreditor(),
            $debtors,
            $claim,
            null,
            $this->persister->resolveClaimRows(new WizardBag(), $claim),
        );

        self::assertNull($skeleton->getId());
        self::assertCount(1, $skeleton->getDebtors(), 'The empty row is skipped.');
        self::assertCount(1, $skeleton->getCountingClaimItems());
        self::assertSame('1000.00', $skeleton->getAmount());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM legal_case WHERE user_id = :id', ['id' => $this->user->getId()]));
    }

    public function testAutoFilledFieldsAreIndexedByStep(): void
    {
        $creditor = $this->newCreditor();
        $creditor->autoFilled = ['name'];
        $debtors = $this->debtors();
        $debtors->debtors[0]->autoFilled = ['cui'];

        $index = $this->persister->aggregateAutoFilledFields($creditor, $debtors, $this->claim());

        self::assertSame(['creditor.name', 'debtor[0].cui'], $index['auto']);
        self::assertContains('creditor.cui', $index['manual']);
        self::assertContains('debtor[0].name', $index['manual']);
        self::assertContains('claim.amount', $index['manual']);
        self::assertNotContains('creditor.name', $index['manual']);
        self::assertNotContains('creditor.autoFilled', $index['manual']);
    }

    /** The exchange trace follows the positions, by the rule the case page applies after an edit. */
    public function testOneForeignPositionWritesTheExchangeTraceOfThatPosition(): void
    {
        $case = $this->persistWithRows([$this->foreignRow('eur-1', 1000.0, 5000.0)], $this->claim())->case;

        self::assertSame('5000.00', $case->getAmount());
        self::assertSame('RON', $case->getCurrency());
        self::assertSame('1000.00', $case->getOriginalAmount());
        self::assertSame('EUR', $case->getOriginalCurrency());
        self::assertEqualsWithDelta(5.0, (float) $case->getExchangeRate(), 0.00001);
        self::assertSame('2024-01-10', $case->getExchangeRateDate()?->format('Y-m-d'));
        self::assertSame('200.00', $case->getStampDuty());
    }

    public function testSeveralForeignPositionsLeaveNoCaseLevelTrace(): void
    {
        $case = $this->persistWithRows([
            $this->foreignRow('eur-1', 1000.0, 5000.0),
            $this->foreignRow('eur-2', 200.0, 1000.0),
        ], $this->claim())->case;

        self::assertSame('6000.00', $case->getAmount());
        self::assertNull($case->getOriginalAmount());
        self::assertNull($case->getOriginalCurrency());
        self::assertNull($case->getExchangeRate());
        self::assertNull($case->getExchangeRateDate());
    }

    /** With every position still waiting for a manual rate, the step 3 figures are the only ones anyone has. */
    public function testPositionsWaitingForAManualRateKeepTheStepThreeTrace(): void
    {
        $claim = $this->claim();
        $claim->currency = 'EUR';
        $conversion = new CurrencyConversionResult(originalAmount: 1000.0, originalCurrency: 'EUR', ronAmount: 4970.0, rate: 4.97, rateDate: new \DateTimeImmutable('2023-12-15'), identity: false);
        $row = $this->foreignRow('eur-1', 1000.0, 5000.0);
        $row->amountRon = null;
        $row->exchangeRate = null;
        $row->exchangeRateDate = null;
        $row->needsManualFx = true;

        $case = $this->persistWithRows([$row], $claim, $conversion)->case;

        self::assertSame('4970.00', $case->getAmount());
        self::assertSame('1000.00', $case->getOriginalAmount());
        self::assertSame('EUR', $case->getOriginalCurrency());
        self::assertSame('4.9700', $case->getExchangeRate());
    }

    private function foreignRow(string $key, float $amount, float $amountRon): ClaimItemRow
    {
        return new ClaimItemRow(
            dedupKey: $key,
            amount: $amount,
            currency: 'EUR',
            documentNumber: strtoupper($key),
            documentDate: new \DateTimeImmutable('2024-01-10'),
            dueDate: new \DateTimeImmutable('2024-02-10'),
            amountRon: $amountRon,
            exchangeRate: $amountRon / $amount,
            exchangeRateDate: new \DateTimeImmutable('2024-01-10'),
            confirmed: true,
        );
    }

    /** @param list<ClaimItemRow> $rows */
    private function persistWithRows(array $rows, Step3ClaimData $claim, ?CurrencyConversionResult $conversion = null): WizardPersistResult
    {
        return $this->persister->persist(
            $this->session(),
            $this->user,
            $this->newCreditor(),
            $this->debtors(),
            $claim,
            [],
            $this->calculations(),
            ['auto' => [], 'manual' => []],
            [],
            null,
            'none',
            $conversion,
            $rows,
        );
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private function newCreditor(): Step1CreditorData
    {
        return new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'Creditor Nou SRL',
            cui: 'RO87654321',
            onrcNumber: 'J40/1/2020',
            address: 'Str. Nouă 2',
            addressCounty: 'București',
            addressLocality: 'Sector 1',
        );
    }

    private function debtors(): Step2DebtorsData
    {
        return new Step2DebtorsData([new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'Debitor SRL',
            cui: 'RO11111111',
            onrcNumber: 'J12/2/2019',
            address: 'Str. Debitor 3',
            addressCounty: 'Cluj',
            addressLocality: 'Cluj-Napoca',
        )]);
    }

    private function claim(): Step3ClaimData
    {
        return new Step3ClaimData(
            amount: 1000.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('2024-01-15'),
            relationshipType: RelationshipType::COMERCIAL,
            description: 'Factura neachitată',
            invoiceNumber: 'F-1',
            invoiceDate: new \DateTimeImmutable('2023-12-15'),
        );
    }

    /** @return array{interest: null, penalty: null, accessoryTotal: float, stampDuty: null, court: null} */
    private function calculations(): array
    {
        return ['interest' => null, 'penalty' => null, 'accessoryTotal' => 0.0, 'stampDuty' => null, 'court' => null];
    }
}
