<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\AuditLog;
use App\Entity\ClaimItem;
use App\Entity\InterestRateConfig;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
use App\Service\Calculation\ClaimInterestAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A case with three invoices, end to end: the wizard persists one position per
 * invoice, the case scalars become their total and earliest due date, and the
 * accessory equals the sum of the per-position calculations.
 */
final class CaseWizardClaimItemsFlowTest extends WebTestCase
{
    private const SESSION_KEY = 'case_wizard_data';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('wizard-claimitems-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Claim');
        $this->user->setLastName('Tester');
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->ensureBnrRate();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        // FK order: audit_log → claim_item → document → debtor → legal_case → creditor → user
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM claim_item WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        $conn->executeStatement("DELETE FROM interest_rate_config WHERE valid_from = '2000-01-01'");

        parent::tearDown();
    }

    public function testThreeInvoicesPersistAsThreePositionsWithTheirOwnDueDates(): void
    {
        $this->primeSession($this->threeRows());
        $this->submit();
        $this->em->clear();

        $case = $this->persistedCase();
        $items = $this->em->getRepository(ClaimItem::class)->findBy(['legalCase' => $case], ['dueDate' => 'ASC']);

        self::assertCount(3, $items);
        self::assertSame(['FF-100', 'FF-200', 'FF-300'], array_map(
            static fn (ClaimItem $i): ?string => $i->getDocumentNumber(),
            $items,
        ));
        self::assertSame('6000.00', $case->getAmount());
        self::assertSame('2025-01-31', $case->getDueDate()?->format('Y-m-d'));
        // Denormalizations follow the earliest position, for the templates that
        // still name a single invoice.
        self::assertSame('FF-100', $case->getInvoiceNumber());
    }

    public function testTheStoredAccessoryEqualsTheSumOfThePerPositionCalculations(): void
    {
        $this->primeSession($this->threeRows());
        $this->submit();
        $this->em->clear();

        $case = $this->persistedCase();
        $items = $this->em->getRepository(ClaimItem::class)->findBy(['legalCase' => $case]);

        $aggregator = static::getContainer()->get(ClaimInterestAggregator::class);
        $expected = $aggregator->aggregate(
            items: $items,
            referenceDate: new \DateTimeImmutable(),
            relationshipType: RelationshipType::COMERCIAL,
        );

        self::assertNotNull($case->getCalculatedInterest());
        self::assertSame(sprintf('%.2f', $expected->total), $case->getCalculatedInterest());
        self::assertGreaterThan(0.0, $expected->total);
    }

    public function testTheStampDutyStaysTwoHundredWhateverTheNumberOfInvoices(): void
    {
        // OUG 80/2013 art. 6 alin. (2): a fixed duty, not a scale.
        $this->primeSession($this->threeRows());
        $this->submit();
        $this->em->clear();

        self::assertSame('200.00', $this->persistedCase()->getStampDuty());
    }

    public function testAnExcludedPositionStaysOnRecordButOutOfTheTotals(): void
    {
        $rows = $this->threeRows();
        $rows[2]->excluded = true;

        $this->primeSession($rows);
        $this->submit();
        $this->em->clear();

        $case = $this->persistedCase();
        $items = $this->em->getRepository(ClaimItem::class)->findBy(['legalCase' => $case]);

        self::assertCount(3, $items, 'The excluded position keeps its trace');
        self::assertSame('3000.00', $case->getAmount());
    }

    public function testTheAuditLogRecordsHowEachPositionWasConfirmed(): void
    {
        $rows = $this->threeRows();
        $rows[2]->excluded = true;

        $this->primeSession($rows);
        $this->submit();
        $this->em->clear();

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
            'user' => $this->user,
        ]);
        self::assertCount(1, $logs);

        $entries = $logs[0]->getNewData()['claim_items'] ?? null;
        self::assertIsArray($entries);
        self::assertCount(3, $entries);
        self::assertSame(['table', 'table', 'excluded'], array_column($entries, 'confirmation'));
        self::assertSame(['FF-100', 'FF-200', 'FF-300'], array_column($entries, 'documentNumber'));
    }

    public function testStepThreeRendersThePositionsTable(): void
    {
        $this->primeSession($this->threeRows());

        $this->client->request('GET', '/case/new/claim');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('claim_items_table_confirmed', $html);
        self::assertStringContainsString('FF-200', $html);
    }

    public function testStepThreeRefusesToAdvanceWithoutTheTableConfirmation(): void
    {
        $this->primeSession($this->threeRows());
        $crawler = $this->client->request('GET', '/case/new/claim');
        $token = $crawler->filter('form input[name="step3_claim[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/claim', $this->claimPayload($token));

        self::assertResponseStatusCodeSame(200, 'The step must not advance to confirmation');
        self::assertStringContainsString(
            'verificat pozițiile de creanță&quot; înainte de a continua.',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testStepThreeAdvancesOnceTheTableIsConfirmed(): void
    {
        $this->primeSession($this->threeRows());
        $crawler = $this->client->request('GET', '/case/new/claim');
        $token = $crawler->filter('form input[name="step3_claim[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/claim', [
            ...$this->claimPayload($token),
            'claim_items_table_confirmed' => '1',
        ]);

        self::assertResponseRedirects('/case/new/confirmation');
    }

    /**
     * The rows are identified by their dedup key, as the table posts them: an
     * exclusion bound to a table position would land on a different invoice
     * whenever the document set changes between render and post.
     *
     * @return array<string, mixed>
     */
    private function claimPayload(string $token): array
    {
        return [
            'step3_claim' => [
                '_token' => $token,
                'amount' => '6000',
                'currency' => 'RON',
                'dueDate' => '2025-01-31',
                'relationshipType' => RelationshipType::COMERCIAL->value,
                'penaltyType' => 'LEGAL_PENALIZATOARE',
            ],
            'claim_items' => [
                ['key' => 'inv:FF-100'],
                ['key' => 'inv:FF-200'],
                ['key' => 'inv:FF-300'],
            ],
        ];
    }

    /** @return list<ClaimItemRow> */
    private function threeRows(): array
    {
        $rows = [];
        foreach ([['FF-100', 1000.0, '2025-01-31'], ['FF-200', 2000.0, '2025-06-30'], ['FF-300', 3000.0, '2025-09-30']] as [$number, $amount, $dueDate]) {
            $rows[] = new ClaimItemRow(
                dedupKey: 'inv:' . $number,
                amount: $amount,
                currency: 'RON',
                documentNumber: $number,
                documentDate: new \DateTimeImmutable($dueDate . ' -30 days'),
                dueDate: new \DateTimeImmutable($dueDate),
                amountRon: $amount,
                causeReference: 'Contract 1/2024',
                confirmed: true,
            );
        }

        return $rows;
    }

    /** @param list<ClaimItemRow> $rows */
    private function primeSession(array $rows): void
    {
        $creditor = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'Acme Creditor SRL',
            cui: 'RO15193236',
            address: 'Str. Exemplu nr. 1, București',
        );

        $debtor = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'Acme Debtor SRL',
            cui: 'RO14186770',
            address: 'Str. Debitor nr. 2, Cluj-Napoca',
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
        );

        $claim = new Step3ClaimData(
            amount: 6000.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('2025-01-31'),
            relationshipType: RelationshipType::COMERCIAL,
        );

        $this->client->request('GET', '/case/new/documents');
        $session = $this->client->getRequest()->getSession();
        $session->set(self::SESSION_KEY, [
            'documentIds' => [],
            'creditor' => $creditor,
            'debtors' => new Step2DebtorsData([$debtor]),
            'claim' => $claim,
            'claimItems' => $rows,
            'claimItemsTableConfirmed' => true,
        ]);
        $session->save();
    }

    private function submit(): void
    {
        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $token,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
            ],
        ]);
    }

    private function persistedCase(): LegalCase
    {
        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);

        return $cases[0];
    }

    /**
     * The interest calculator needs a reference rate in force at the oldest due
     * date; a sentinel far in the past covers every fixture here.
     */
    private function ensureBnrRate(): void
    {
        $existing = $this->em->getRepository(InterestRateConfig::class)
            ->findOneBy(['validFrom' => new \DateTimeImmutable('2000-01-01')]);
        if ($existing !== null) {
            return;
        }

        $config = new InterestRateConfig();
        $config->setValidFrom(new \DateTimeImmutable('2000-01-01'));
        $config->setReferenceRate('6.00');
        $this->em->persist($config);
        $this->em->flush();
    }
}
