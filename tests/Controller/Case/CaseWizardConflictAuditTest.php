<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\DTO\Extraction\ConflictResolution;
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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The last screen before a filing exists is also the last chance to catch a
 * disagreement nobody settled, and the only place the decision can be written
 * down for good.
 *
 * Why the audit entry matters: a payment order is contested by opposition
 * (CPC art. 1023), and the creditor then has to say why the sum claimed is the
 * one in the invoice rather than the one in the reconciliation. Without a record
 * of what the documents offered and what was retained, that answer rests on
 * memory.
 */
final class CaseWizardConflictAuditTest extends WebTestCase
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
        $this->user->setEmail('wizard-conflict-audit-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Conflict');
        $this->user->setLastName('Auditor');
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

    public function testConfirmationRefusesToSubmitOverAnUnsettledDivergence(): void
    {
        $this->primeSession([]);

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');
        self::assertSame(1, $crawler->filter('[data-testid="conflicts-unsettled"]')->count());

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => ['_token' => $token, 'acceptTerms' => '1', 'acceptDataAccuracy' => '1'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]));
    }

    public function testTheAuditLogStatesWhatWasOfferedAndWhichDocumentWasRetained(): void
    {
        $documentIds = $this->primeSession([]);
        $key = 'creditor:-:cui:wizard.conflict.field.cui';
        $this->primeSession([
            $key => new ConflictResolution(
                conflictKey: $key,
                scope: ConflictScope::CREDITOR,
                field: 'cui',
                entityKey: null,
                value: '14186770',
                optionIndex: 1,
                documentId: $documentIds[1],
                documentType: DocumentType::FACTURA,
                optionSignature: '14186770',
            ),
        ], $documentIds);

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => ['_token' => $token, 'acceptTerms' => '1', 'acceptDataAccuracy' => '1'],
        ]);

        self::assertResponseRedirects();

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'user' => $this->user,
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
        ]);
        self::assertNotNull($log);

        $entries = $log->getNewData()['conflict_resolutions'] ?? null;
        self::assertIsArray($entries);

        $resolved = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['key'] === $key,
        ));
        self::assertCount(1, $resolved);
        self::assertCount(2, $resolved[0]['options']);
        self::assertSame('14186770', $resolved[0]['resolution']['value']);
        self::assertSame($documentIds[1], $resolved[0]['resolution']['documentId']);
        self::assertFalse($resolved[0]['resolution']['manual']);
    }

    /**
     * Two documents naming two registration numbers for the creditor, plus the
     * steps already filled, so the run starts at the confirmation screen.
     *
     * @param array<string, ConflictResolution> $resolutions
     * @param ?list<int> $documentIds reuses the documents of a previous call
     * @return list<int>
     */
    private function primeSession(array $resolutions, ?array $documentIds = null): array
    {
        $documentIds ??= [
            $this->document('Cedent SRL', '15193236'),
            $this->document('Cesionar SRL', '14186770'),
        ];

        $creditor = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'Cesionar SRL',
            cui: 'RO14186770',
            address: 'Str. Exemplu nr. 1, București',
        );
        $debtor = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'Acme Debtor SRL',
            cui: 'RO15193236',
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
            'documentIds' => $documentIds,
            'creditor' => $creditor,
            'debtors' => new Step2DebtorsData([$debtor]),
            'claim' => $claim,
            'claimItems' => null,
            'claimItemsTableConfirmed' => false,
            'conflictResolutions' => $resolutions,
        ]);
        $session->save();

        return $documentIds;
    }

    private function document(string $name, string $cui): int
    {
        $document = new Document();
        $document->setUploadedBy($this->user);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('factura-' . $cui . '.pdf');
        $document->setStoredFilename('stored-' . uniqid() . '.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'creditor' => [
                'personType' => 'PJ',
                'name' => $name,
                'cui' => $cui,
                'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95],
            ],
        ]);
        $this->em->persist($document);
        $this->em->flush();

        return (int) $document->getId();
    }
}
