<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What survives the way the wizard is actually used, and what the file says
 * about it afterwards.
 *
 * Two things are tested here that nothing else covers: a decision has to
 * outlive the ordinary accident of a form refused for another reason, and the
 * record of the decision has to name the document it was read from in terms
 * that still mean something once the case is archived.
 */
final class CaseWizardConflictRecordTest extends WebTestCase
{
    private const SESSION_KEY = 'case_wizard_data';
    private const CONFLICT_KEY_CREDITOR_CUI = 'creditor:-:cui:wizard.conflict.field.cui';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('wizard-conflict-record-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Conflict');
        $this->user->setLastName('Recorder');
        $this->user->setAiProcessingAgreementAt(new \DateTimeImmutable());
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM claim_item WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    /**
     * A field left blank is the most ordinary way to see this page twice.
     * Losing the decisions between the documents on the way would mean the
     * lawyer reads the invoices again because they mistyped an address.
     */
    public function testTheChoiceSurvivesAFormRefusedForAnotherReason(): void
    {
        $this->primeCreditorDocuments();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => [
                '_token' => $this->fieldValue($crawler, 'step1_creditor[_token]'),
                'personType' => 'PJ',
                'name' => 'Cesionar SRL',
                'cui' => 'RO14186770',
                'onrcNumber' => 'J40/1234/2018',
                // The address is required, so the step is refused.
                'address' => '',
            ],
            'prefill_conflict' => [self::CONFLICT_KEY_CREDITOR_CUI => '1'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $resolutions = $this->bag()['conflictResolutions'] ?? [];
        self::assertArrayHasKey(
            self::CONFLICT_KEY_CREDITOR_CUI,
            $resolutions,
            'The decision between two documents cannot be lost over a blank address',
        );

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $checked = $crawler
            ->filter(sprintf('input[name="prefill_conflict[%s]"]', self::CONFLICT_KEY_CREDITOR_CUI))
            ->reduce(static fn (Crawler $node): bool => $node->attr('checked') !== null);
        self::assertSame(1, $checked->count(), 'The decision is still on screen');
    }

    /**
     * The record has to name the file, not its row id. A numeric id points at a
     * row that can be deleted; the name is what the lawyer filed and the hash is
     * what ties the decision to the document in the bundle.
     */
    public function testTheRecordNamesTheFileEachValueWasReadFrom(): void
    {
        $this->primeCreditorDocuments();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => [
                '_token' => $this->fieldValue($crawler, 'step1_creditor[_token]'),
                'personType' => 'PJ',
                'name' => $this->fieldValue($crawler, 'step1_creditor[name]'),
                'cui' => $this->fieldValue($crawler, 'step1_creditor[cui]'),
                'onrcNumber' => 'J40/1234/2018',
                'address' => 'Str. Exemplu nr. 1, București',
            ],
            'prefill_conflict' => [self::CONFLICT_KEY_CREDITOR_CUI => '1'],
        ]);
        self::assertResponseRedirects('/case/new/debtor');

        $this->fillStepsPastCreditor();
        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $this->fieldValue($crawler, 'step4_confirmation[_token]'),
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
            ],
        ]);
        self::assertResponseRedirects();

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'user' => $this->user,
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
        ]);
        self::assertNotNull($log);

        $entry = null;
        foreach ($log->getNewData()['conflict_resolutions'] ?? [] as $candidate) {
            if (($candidate['key'] ?? null) === self::CONFLICT_KEY_CREDITOR_CUI) {
                $entry = $candidate;
            }
        }
        self::assertIsArray($entry);
        self::assertSame('factura-1.pdf', $entry['resolution']['documentFilename']);
        self::assertStringStartsWith('hash-1-', (string) $entry['resolution']['documentContentHash']);
        self::assertSame('factura-0.pdf', $entry['options'][0]['documentFilename']);
        self::assertSame('factura-1.pdf', $entry['options'][1]['documentFilename']);
    }

    /**
     * Where the documents offer nothing to choose between, all the lawyer can do
     * is say they have read the disagreement. That statement is about the
     * disagreements in front of them: a document uploaded afterwards can raise
     * another one, and carrying the old statement onto it would put in the file
     * an assumption nobody made.
     */
    public function testAnAcknowledgementDoesNotCoverADisagreementThatAppearsLater(): void
    {
        $ids = $this->primeDocuments([
            $this->debtorWithClaim('Alfa Construct SRL', '15193236', 1000.0),
            $this->debtorWithClaim('Beta Logistic SRL', '14186770', 2000.0),
        ]);

        $crawler = $this->client->request('GET', '/case/new/debtor');
        self::assertSame(1, $crawler->filter('[data-testid="prefill-conflicts-ack"]')->count());
        $this->postDebtor($crawler, ['prefill_conflicts_acknowledged' => '1']);
        self::assertResponseRedirects('/case/new/claim');

        // Four more parties: over the product cap, which is a second blocking
        // disagreement with nothing to choose between.
        $more = $this->primeDocuments([
            $this->debtorWithClaim('Gama SRL', '42000006', 3000.0),
            $this->debtorWithClaim('Delta SRL', '13548146', 4000.0),
            $this->debtorWithClaim('Epsilon SRL', '16018400', 5000.0),
            $this->debtorWithClaim('Zeta SRL', '14399840', 6000.0),
        ]);
        $this->writeBag(['documentIds' => [...$ids, ...$more]]);

        $crawler = $this->client->request('GET', '/case/new/debtor');
        $this->postDebtor($crawler);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'A disagreement the lawyer has never seen cannot count as assumed',
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function postDebtor(Crawler $crawler, array $extra = []): void
    {
        $this->client->request('POST', '/case/new/debtor', [
            'step2_debtors' => [
                '_token' => $this->fieldValue($crawler, 'step2_debtors[_token]'),
                'debtors' => [[
                    'personType' => 'PJ',
                    'name' => 'Alfa Construct SRL',
                    'cui' => 'RO15193236',
                    'onrcNumber' => 'J12/100/2019',
                    'address' => 'Str. Debitor nr. 2, Cluj-Napoca',
                    'bpiVerifiedToday' => '1',
                ]],
            ],
            ...$extra,
        ]);
    }

    private function fieldValue(Crawler $crawler, string $name): ?string
    {
        $node = $crawler->filter(sprintf('input[name="%s"]', $name));
        if ($node->count() > 0) {
            return $node->first()->attr('value');
        }

        $selected = $crawler
            ->filter(sprintf('select[name="%s"] option', $name))
            ->reduce(static fn (Crawler $option): bool => $option->attr('selected') !== null);

        return $selected->count() > 0 ? $selected->first()->attr('value') : null;
    }

    private function fillStepsPastCreditor(): void
    {
        $this->writeBag([
            'debtors' => new Step2DebtorsData([new Step2DebtorEntry(
                personType: PersonType::PJ,
                name: 'Acme Debtor SRL',
                cui: 'RO15193236',
                address: 'Str. Debitor nr. 2, Cluj-Napoca',
                anafStatus: AnafStatus::ACTIV,
                anafCheckedAt: new \DateTimeImmutable('-1 day'),
                insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
            )]),
            'claim' => new Step3ClaimData(
                amount: 6000.0,
                currency: 'RON',
                dueDate: new \DateTimeImmutable('2025-01-31'),
                relationshipType: RelationshipType::COMERCIAL,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bag(): array
    {
        $this->client->request('GET', '/case/new/documents');
        $raw = $this->client->getRequest()->getSession()->get(self::SESSION_KEY, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function writeBag(array $changes): void
    {
        $this->client->request('GET', '/case/new/documents');
        $session = $this->client->getRequest()->getSession();
        $raw = $session->get(self::SESSION_KEY, []);
        $session->set(self::SESSION_KEY, [...(is_array($raw) ? $raw : []), ...$changes]);
        $session->save();
    }

    /**
     * @return list<int>
     */
    private function primeCreditorDocuments(): array
    {
        return $this->primeDocuments([
            $this->creditorPayload('Cedent SRL', '15193236'),
            $this->creditorPayload('Cesionar SRL', '14186770'),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     * @return list<int>
     */
    private function primeDocuments(array $payloads): array
    {
        $ids = [];
        // The kernel reboots between requests, so the user this test holds is
        // detached by the time a second batch is written.
        $owner = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $owner);
        foreach ($payloads as $index => $payload) {
            $document = new Document();
            $document->setUploadedBy($owner);
            $document->setDocumentType(DocumentType::FACTURA);
            $document->setOriginalFilename('factura-' . $index . '.pdf');
            $document->setStoredFilename('stored-' . uniqid() . '.pdf');
            $document->setContentHash('hash-' . $index . '-' . uniqid());
            $document->setMimeType('application/pdf');
            $document->setFileSize(1024);
            $document->setExtractionStatus(ExtractionStatus::COMPLETED);
            $document->setExtractedData($payload);
            $this->em->persist($document);
            $this->em->flush();
            $ids[] = (int) $document->getId();
        }

        $this->client->request('GET', '/case/new/documents');
        $session = $this->client->getRequest()->getSession();
        $session->set(self::SESSION_KEY, ['documentIds' => $ids]);
        $session->save();

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function creditorPayload(string $name, string $cui): array
    {
        return [
            'schemaVersion' => 2,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'creditor' => [
                'personType' => 'PJ',
                'name' => $name,
                'cui' => $cui,
                'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function debtorWithClaim(string $name, string $cui, float $amount): array
    {
        return [
            'schemaVersion' => 2,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'debtors' => [[
                'personType' => 'PJ',
                'name' => $name,
                'cui' => $cui,
                'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95],
            ]],
            'claim' => [
                'amount' => $amount,
                'currency' => 'RON',
                'confidencePerField' => ['amount' => 0.95, 'currency' => 0.95],
            ],
        ];
    }
}
