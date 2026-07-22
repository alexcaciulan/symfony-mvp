<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\ConflictScope;
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
 * Adversarial pass over the choice the lawyer makes in the conflicts panel.
 *
 * Showing the disagreement and recording that a choice was made is only half of
 * it: the value that ends up in the petition has to be the one the lawyer
 * retained. A registration number identifies the party sued (CPC art. 194
 * lit. a), so a panel that records "the invoice was chosen" over a file that
 * carries the contract's number states something the file contradicts.
 */
final class CaseWizardConflictChoiceAdversarialTest extends WebTestCase
{
    private const SESSION_KEY = 'case_wizard_data';

    /** Both are valid registration numbers, so the form itself never objects. */
    private const CUI_FIRST_DOCUMENT = '15193236';
    private const CUI_SECOND_DOCUMENT = '14186770';

    /** Valid, and a substring of neither document, so a pass cannot be accidental. */
    private const CUI_TYPED_BY_THE_LAWYER = '42000006';

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
        $this->user->setEmail('wizard-conflict-choice-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Conflict');
        $this->user->setLastName('Chooser');
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
     * The lawyer does not retype the field: it arrives prefilled with the value
     * the ranking preferred, and the only thing they touch is the radio naming
     * the other document. If the step keeps the prefilled value, the choice is
     * decoration.
     */
    public function testTheChosenNumberReplacesTheSuggestedOneInTheStepData(): void
    {
        $this->primeCreditorDocuments();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $rendered = $this->fieldValue($crawler, 'step1_creditor[cui]');
        self::assertStringContainsString(self::CUI_FIRST_DOCUMENT, (string) $rendered, 'The step starts on the ranked value');

        $this->submitCreditorAsRendered($crawler, ['prefill_conflict' => [self::CONFLICT_KEY_CREDITOR_CUI => '1']]);
        self::assertResponseRedirects('/case/new/debtor');

        $creditor = $this->bag()['creditor'] ?? null;
        self::assertInstanceOf(Step1CreditorData::class, $creditor);
        self::assertStringContainsString(
            self::CUI_SECOND_DOCUMENT,
            (string) $creditor->cui,
            'The value carried forward has to be the one the lawyer retained, not the one the ranking suggested',
        );
    }

    /**
     * Walking to the next step and back is ordinary in a wizard, and the value
     * shown on return is the one the filing will carry.
     */
    public function testTheChosenNumberIsStillTheOneShownAfterAWalkForwardAndBack(): void
    {
        $this->primeCreditorDocuments();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $this->submitCreditorAsRendered($crawler, ['prefill_conflict' => [self::CONFLICT_KEY_CREDITOR_CUI => '1']]);
        self::assertResponseRedirects('/case/new/debtor');

        $this->client->request('GET', '/case/new/debtor');
        $crawler = $this->client->request('GET', '/case/new/creditor');

        $checked = $crawler
            ->filter(sprintf('input[name="prefill_conflict[%s]"]', self::CONFLICT_KEY_CREDITOR_CUI))
            ->reduce(static fn (Crawler $node): bool => $node->attr('checked') !== null);
        self::assertSame(1, $checked->count(), 'The decision has to still be on screen');
        self::assertSame('1', $checked->first()->attr('value'));

        self::assertStringContainsString(
            self::CUI_SECOND_DOCUMENT,
            (string) $this->fieldValue($crawler, 'step1_creditor[cui]'),
            'The form has to show what was decided, not what the ranking suggests',
        );
    }

    /**
     * A step that refuses the submission is worth nothing if the following
     * steps can be posted straight through. The blocking conflict has to still
     * be there at the end, where the filing is created.
     */
    public function testABlockingConflictCannotBeWalkedPastByPostingTheFollowingSteps(): void
    {
        $this->primeCreditorDocuments();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $this->submitCreditorAsRendered($crawler);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // The bag is filled as though the lawyer had completed the steps, which
        // is the most permissive state the bypass could reach.
        $this->fillStepsPastCreditor();

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        self::assertResponseIsSuccessful();
        $token = $this->fieldValue($crawler, 'step4_confirmation[_token]');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => ['_token' => $token, 'acceptTerms' => '1', 'acceptDataAccuracy' => '1'],
        ]);

        self::assertSame(
            [],
            $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]),
            'A disagreement about who the creditor is cannot end in a filing',
        );
    }

    /**
     * The typed value goes into the petition exactly like a value read from a
     * document, so it has to clear the same check. A registration number that
     * fails the control digit belongs to nobody, and the project already knows
     * how to say so.
     */
    public function testATypedRegistrationNumberThatFailsItsControlDigitIsRefused(): void
    {
        $this->primeCreditorDocuments();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $this->submitCreditorAsRendered($crawler, [
            'prefill_conflict' => [self::CONFLICT_KEY_CREDITOR_CUI => 'manual'],
            'prefill_conflict_manual' => [self::CONFLICT_KEY_CREDITOR_CUI => '12345678'],
        ]);

        $resolutions = $this->bag()['conflictResolutions'] ?? [];
        self::assertArrayNotHasKey(
            self::CONFLICT_KEY_CREDITOR_CUI,
            $resolutions,
            'An invalid registration number cannot be retained as the decision',
        );
    }

    /**
     * A valid typed value has to reach the file and say it came from nobody's
     * document.
     */
    public function testAValidTypedNumberIsCarriedForwardAndMarkedManual(): void
    {
        $this->primeCreditorDocuments();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $this->submitCreditorAsRendered($crawler, [
            'prefill_conflict' => [self::CONFLICT_KEY_CREDITOR_CUI => 'manual'],
            'prefill_conflict_manual' => [self::CONFLICT_KEY_CREDITOR_CUI => self::CUI_TYPED_BY_THE_LAWYER],
        ]);
        self::assertResponseRedirects('/case/new/debtor');

        $bag = $this->bag();
        $resolution = ($bag['conflictResolutions'] ?? [])[self::CONFLICT_KEY_CREDITOR_CUI] ?? null;
        self::assertInstanceOf(ConflictResolution::class, $resolution);
        self::assertTrue($resolution->isManual());
        self::assertSame(self::CUI_TYPED_BY_THE_LAWYER, $resolution->value);

        $creditor = $bag['creditor'] ?? null;
        self::assertInstanceOf(Step1CreditorData::class, $creditor);
        self::assertStringContainsString(
            self::CUI_TYPED_BY_THE_LAWYER,
            (string) $creditor->cui,
            'The typed value has to be the one carried forward',
        );
    }

    /**
     * What the audit says and what the file says have to be one story.
     */
    public function testTheAuditAndThePersistedCaseTellTheSameStory(): void
    {
        $documentIds = $this->primeCreditorDocuments();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $this->submitCreditorAsRendered($crawler, ['prefill_conflict' => [self::CONFLICT_KEY_CREDITOR_CUI => '1']]);
        self::assertResponseRedirects('/case/new/debtor');

        $this->fillStepsPastCreditor();

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $this->fieldValue($crawler, 'step4_confirmation[_token]');
        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => ['_token' => $token, 'acceptTerms' => '1', 'acceptDataAccuracy' => '1'],
        ]);
        self::assertResponseRedirects();

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'user' => $this->user,
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
        ]);
        self::assertNotNull($log);

        $entries = $log->getNewData()['conflict_resolutions'] ?? [];
        self::assertIsArray($entries);
        $entry = null;
        foreach ($entries as $candidate) {
            if (($candidate['key'] ?? null) === self::CONFLICT_KEY_CREDITOR_CUI) {
                $entry = $candidate;
            }
        }
        self::assertIsArray($entry, 'The disagreement put to the lawyer has to be in the record');
        self::assertSame(ConflictScope::CREDITOR->value, $entry['scope']);
        self::assertSame('cui', $entry['field']);
        self::assertCount(2, $entry['options'], 'Both values offered belong in the record');
        self::assertSame(self::CUI_SECOND_DOCUMENT, $entry['resolution']['value']);
        self::assertSame($documentIds[1], $entry['resolution']['documentId']);
        self::assertFalse($entry['resolution']['manual']);

        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);
        self::assertStringContainsString(
            self::CUI_SECOND_DOCUMENT,
            (string) $cases[0]->getCreditor()?->getCui(),
            'The filing has to carry the number the audit says was retained',
        );
    }

    /**
     * Choosing the number stated by the second document means the party is the
     * one that document describes, so its name comes along. A name from one
     * file and a number from another is a legal person that exists nowhere.
     */
    public function testThePinnedDocumentSuppliesTheWholeParty(): void
    {
        $documentIds = $this->primeCreditorDocuments();
        $this->writeBag([
            'documentIds' => $documentIds,
            'conflictResolutions' => [
                self::CONFLICT_KEY_CREDITOR_CUI => new ConflictResolution(
                    conflictKey: self::CONFLICT_KEY_CREDITOR_CUI,
                    scope: ConflictScope::CREDITOR,
                    field: 'cui',
                    entityKey: null,
                    value: self::CUI_SECOND_DOCUMENT,
                    optionIndex: 1,
                    documentId: $documentIds[1],
                    documentType: DocumentType::FACTURA,
                    optionSignature: self::CUI_SECOND_DOCUMENT,
                ),
            ],
        ]);

        $crawler = $this->client->request('GET', '/case/new/creditor');

        self::assertStringContainsString(self::CUI_SECOND_DOCUMENT, (string) $this->fieldValue($crawler, 'step1_creditor[cui]'));
        self::assertSame('Cesionar SRL', $this->fieldValue($crawler, 'step1_creditor[name]'));
    }

    /**
     * The same invoice read twice with two sums, settled on the claim step: the
     * sum retained is what the court is asked to order paid, so it has to be in
     * the position the file carries and not only in the panel.
     */
    public function testTheChosenSumOfADisputedInvoiceIsTheOneCarriedForward(): void
    {
        $this->primeDocuments([
            $this->invoicePayload('FF-100', 1000.0),
            $this->invoicePayload('FF-100', 1200.0),
        ]);

        $crawler = $this->client->request('GET', '/case/new/claim');
        self::assertResponseIsSuccessful();
        $radios = $crawler->filter('[data-testid="prefill-conflict-option"]');
        self::assertGreaterThan(0, $radios->count(), 'The two readings of one invoice are a decision, not a ranking');

        $key = null;
        foreach ($radios as $node) {
            $name = (string) $node->getAttribute('name');
            if (str_contains($name, ':amount:')) {
                $key = substr($name, strlen('prefill_conflict['), -1);
                break;
            }
        }
        self::assertIsString($key);

        $rowKey = $this->claimRowKey($crawler);
        $this->client->request('POST', '/case/new/claim', [
            'step3_claim' => [
                '_token' => $this->fieldValue($crawler, 'step3_claim[_token]'),
                'amount' => '1000',
                'currency' => 'RON',
                'dueDate' => '2025-01-31',
                'relationshipType' => RelationshipType::COMERCIAL->value,
                'penaltyType' => 'LEGAL_PENALIZATOARE',
            ],
            'claim_items' => [['key' => $rowKey, 'confirmed' => '1']],
            'claim_items_table_confirmed' => '1',
            'prefill_conflict' => [$key => '1'],
        ]);

        self::assertResponseRedirects('/case/new/confirmation');

        $rows = array_values(array_filter(
            $this->bag()['claimItems'] ?? [],
            static fn (ClaimItemRow $row): bool => !$row->excluded,
        ));
        self::assertCount(1, $rows, 'One invoice read twice is one position');
        self::assertSame(1200.0, $rows[0]->amount, 'The retained reading has to be the sum the position claims');
        self::assertSame(1200.0, $rows[0]->amountRon, 'Totals and interest read the RON figure');
    }

    /**
     * The regression that costs the most: nearly every file has no divergence
     * at all, and none of this may add a step or a field to those.
     */
    public function testAFileWithoutDisagreementWalksTheStepsUnchanged(): void
    {
        $this->primeDocuments([$this->creditorPayload('Unica SRL', self::CUI_FIRST_DOCUMENT)]);

        $crawler = $this->client->request('GET', '/case/new/creditor');
        self::assertSame(0, $crawler->filter('[data-testid="prefill-conflicts"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="prefill-conflict-option"]')->count());

        // Posted with nothing the panel would have added: no choice, no
        // acknowledgement.
        $this->submitCreditorAsRendered($crawler);
        self::assertResponseRedirects('/case/new/debtor');

        self::assertSame([], $this->bag()['conflictResolutions'] ?? null);
    }

    /**
     * A second opinion on the county is worth reading, not worth stopping the
     * file: the aggregation has a defensible answer and the lawyer can override
     * it on the field itself.
     */
    public function testADivergenceThatDoesNotBlockLetsTheStepThroughAndStaysVisible(): void
    {
        $this->primeDocuments([
            $this->debtorPayload('Alfa Construct SRL', self::CUI_FIRST_DOCUMENT, 'Cluj'),
            $this->debtorPayload('Alfa Construct SRL', self::CUI_FIRST_DOCUMENT, 'București'),
        ]);

        $crawler = $this->client->request('GET', '/case/new/debtor');
        self::assertSame(1, $crawler->filter('[data-testid="prefill-conflicts"]')->count());
        $token = $this->fieldValue($crawler, 'step2_debtors[_token]');

        $this->client->request('POST', '/case/new/debtor', [
            'step2_debtors' => [
                '_token' => $token,
                'debtors' => [[
                    'personType' => 'PJ',
                    'name' => 'Alfa Construct SRL',
                    'cui' => 'RO' . self::CUI_FIRST_DOCUMENT,
                    'onrcNumber' => 'J12/100/2019',
                    'address' => 'Str. Debitor nr. 2, Cluj-Napoca',
                    'bpiVerifiedToday' => '1',
                ]],
            ],
        ]);

        self::assertResponseRedirects('/case/new/claim');

        $crawler = $this->client->request('GET', '/case/new/debtor');
        self::assertSame(1, $crawler->filter('[data-testid="prefill-conflicts"]')->count(), 'The divergence stays readable after the step is passed');
    }

    /**
     * Posts step 1 with exactly the values the page rendered, plus whatever the
     * panel adds. The address is typed because no document states one; every
     * other field is left as the prefill produced it, which is how the step is
     * used.
     *
     * @param array<string, mixed> $extra
     */
    private function submitCreditorAsRendered(Crawler $crawler, array $extra = []): void
    {
        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => [
                '_token' => $this->fieldValue($crawler, 'step1_creditor[_token]'),
                'personType' => $this->fieldValue($crawler, 'step1_creditor[personType]') ?? PersonType::PJ->value,
                'name' => $this->fieldValue($crawler, 'step1_creditor[name]'),
                'cui' => $this->fieldValue($crawler, 'step1_creditor[cui]'),
                'onrcNumber' => 'J40/1234/2018',
                'address' => 'Str. Exemplu nr. 1, București',
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

    /**
     * The debtor and the claim, written straight into the bag: these tests are
     * about the creditor divergence, and walking two more forms would only add
     * ways for them to fail for another reason.
     */
    private function fillStepsPastCreditor(): void
    {
        $this->writeBag([
            'creditor' => new Step1CreditorData(
                personType: PersonType::PJ,
                name: 'Cedent SRL',
                cui: 'RO' . self::CUI_FIRST_DOCUMENT,
                address: 'Str. Exemplu nr. 1, București',
            ),
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
     * Two documents naming two registration numbers for the party bringing the
     * claim: the assignment-of-debt case, where which of the two is the
     * creditor is a decision and not a ranking.
     *
     * @return list<int>
     */
    private function primeCreditorDocuments(): array
    {
        return $this->primeDocuments([
            $this->creditorPayload('Cedent SRL', self::CUI_FIRST_DOCUMENT),
            $this->creditorPayload('Cesionar SRL', self::CUI_SECOND_DOCUMENT),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     * @return list<int>
     */
    private function primeDocuments(array $payloads): array
    {
        $ids = [];
        foreach ($payloads as $index => $payload) {
            $document = new Document();
            $document->setUploadedBy($this->user);
            $document->setDocumentType(DocumentType::FACTURA);
            $document->setOriginalFilename('factura-' . $index . '.pdf');
            $document->setStoredFilename('stored-' . uniqid() . '.pdf');
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
     * The dedup key of the single position on the table, read off the page: the
     * key is derived from what the documents state and hardcoding it here would
     * test the derivation rather than the choice.
     */
    private function claimRowKey(Crawler $crawler): string
    {
        $input = $crawler->filter('input[name^="claim_items"][name$="[key]"]');
        self::assertGreaterThan(0, $input->count());

        return (string) $input->first()->attr('value');
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(string $number, float $amount): array
    {
        return [
            'schemaVersion' => 2,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'claim' => [
                'amount' => $amount,
                'currency' => 'RON',
                'invoiceNumber' => $number,
                'invoiceDate' => '2025-01-01',
                'dueDate' => '2025-01-31',
                'confidencePerField' => [
                    'amount' => 0.95,
                    'currency' => 0.95,
                    'invoiceNumber' => 0.95,
                    'invoiceDate' => 0.95,
                    'dueDate' => 0.95,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function debtorPayload(string $name, string $cui, string $county): array
    {
        return [
            'schemaVersion' => 2,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'debtors' => [[
                'personType' => 'PJ',
                'name' => $name,
                'cui' => $cui,
                'county' => $county,
                'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95, 'county' => 0.95],
            ]],
        ];
    }
}
