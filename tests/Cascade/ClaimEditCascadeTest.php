<?php

declare(strict_types=1);

namespace App\Tests\Cascade;

use App\Entity\AuditLog;
use App\Entity\ClaimItem;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionPipeline;
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Message\ExtractDataMessage;
use App\MessageHandler\ExtractDataMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The whole path of an invoice added from the claim card, with no mock: the
 * upload stores it on the case and queues the reading, the real handler runs
 * the cascade (local tiers only, on a fixture nothing can read, so it ends
 * FAILED), the dialog then offers the empty form with the document attached,
 * and saving it refreshes the card, the KPI grid and creates the limitation
 * term of the new due date.
 */
final class ClaimEditCascadeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('claim-cascade-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Cascade');
        $this->user->setLastName('Claim');
        $this->user->setExtractionPipeline(ExtractionPipeline::LEGACY_CASCADE);
        $this->user->setExtractionMode(ExtractionMode::LOCAL_ONLY);
        $this->em->persist($this->user);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Cascade Creditor SRL');
        $creditor->setAddress('Str. Cascade 1, Cluj-Napoca');
        $creditor->setCui('RO12345678');
        $this->em->persist($creditor);

        $debtor = new Debtor();
        $debtor->setUser($this->user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Cascade Debtor SRL');
        $debtor->setAddress('Str. Memorandumului 1, Cluj-Napoca');
        $debtor->setAddressCounty('Cluj');
        $debtor->setAddressLocality('Cluj-Napoca');
        $debtor->setCui('RO87654321');
        $this->em->persist($debtor);
        $link = new LegalCaseDebtor($debtor);
        $link->setAnafStatus(AnafStatus::ACTIV);
        $link->setAnafCheckedAt(new \DateTimeImmutable());
        $link->setInsolvencyCheckedAt(new \DateTimeImmutable());

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setStatus(CaseStatus::AMIABIL);
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        $this->case->setAmount('1000.00');
        $this->case->setCurrency('RON');
        $this->case->setDueDate(new \DateTime('-100 days'));
        $this->case->addDebtor($link);
        $this->em->persist($this->case);

        $item = new ClaimItem();
        $item->setDocumentNumber('F-1');
        $item->setDocumentDate(new \DateTimeImmutable('-130 days'));
        $item->setDueDate(new \DateTimeImmutable('-100 days'));
        $item->setAmount('1000.00');
        $item->setCurrency('RON');
        $item->setAmountRon('1000.00');
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('cascade-' . uniqid('', true));
        $item->setDebtor($link);
        $this->case->addClaimItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM claim_item WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        $caseDir = $this->uploadsDir . '/cases/' . $this->case->getId();
        if (is_dir($caseDir)) {
            foreach (glob($caseDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($caseDir);
        }

        parent::tearDown();
    }

    public function testUploadReadingFormAndSaveRefreshTheClaim(): void
    {
        $caseId = $this->case->getId();

        // 1) Upload from the claim card: stored on the case, reading queued.
        $this->client->request(
            'POST',
            sprintf('/case/%d/claim/invoice', $caseId),
            ['claim_invoice' => ['_token' => $this->token('claim_invoice')]],
            ['claim_invoice' => ['file' => $this->makePdf()]],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-claim-invoice-state="processing"', (string) $this->client->getResponse()->getContent());

        $document = $this->em->getRepository(Document::class)->findOneBy(['legalCase' => $caseId]);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentType::FACTURA, $document->getDocumentType());
        self::assertSame(ExtractionStatus::PENDING, $document->getExtractionStatus());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ExtractDataMessage::class, $message);

        // 2) The real handler runs the cascade on the stored file.
        /** @var ExtractDataMessageHandler $handler */
        $handler = static::getContainer()->get(ExtractDataMessageHandler::class);
        $handler($message);
        $this->em->clear();
        $document = $this->em->find(Document::class, $document->getId());
        self::assertTrue($document->getExtractionStatus()->isTerminal(), 'The cascade must end in a terminal status.');
        self::assertSame($caseId, $document->getLegalCase()?->getId(), 'The reading keeps the document on the case.');

        // 3) The dialog polls the state and gets the form, with the document attached.
        $crawler = $this->client->request('GET', sprintf('/case/%d/claim/invoice/%d', $caseId, $document->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('false', $crawler->filter('#claim-invoice-status-body')->attr('data-poll-frame-pending-value'));
        self::assertCount(1, $crawler->filter('form[data-testid="claim-position-form-document"]'));
        self::assertSame((string) $document->getId(), $crawler->filter('input[name="claim_position[sourceDocumentId]"]')->attr('value'));

        // 4) Saving the position refreshes the card and the KPI grid and creates the term.
        $this->client->request('POST', sprintf('/case/%d/claim/invoice/%d/add', $caseId, $document->getId()), [
            'claim_position' => [
                '_token' => $this->token('claim_position'),
                'documentNumber' => 'F-2025-0210',
                'documentDate' => (new \DateTimeImmutable('-55 days'))->format('Y-m-d'),
                'dueDate' => (new \DateTimeImmutable('-25 days'))->format('Y-m-d'),
                'amount' => '12500.00',
                'currency' => 'RON',
                'sourceDocumentId' => (string) $document->getId(),
            ],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-claim-card"', $body);
        self::assertStringContainsString('target="case-kpi-grid"', $body);
        self::assertStringContainsString('F-2025-0210', $body);
        self::assertStringContainsString('13.500,00', $body);

        $this->em->clear();
        $case = $this->em->find(LegalCase::class, $caseId);
        self::assertSame('13500.00', $case->getAmount());
        $added = $this->em->getRepository(ClaimItem::class)->findOneBy(['legalCase' => $caseId, 'documentNumber' => 'F-2025-0210']);
        self::assertInstanceOf(ClaimItem::class, $added);
        self::assertSame($document->getId(), $added->getSourceDocument()?->getId());
        $terms = $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $caseId, 'type' => DeadlineType::PRESCRIPTIE]);
        self::assertNotEmpty($terms, 'The new due date gets its limitation term.');
    }

    /**
     * The same path on an invoice the local parser can actually read: the
     * reading ends COMPLETED, the dialog comes back prefilled with the values
     * read and the parties checked, and saving keeps what was read next to
     * what was saved.
     */
    public function testARealInvoiceIsReadPrefilledAndSaved(): void
    {
        $caseId = $this->case->getId();
        // The parties the fixture names, so the party check can match them.
        $this->case->getCreditor()->setCui('RO15193236');
        $this->case->getPrimaryDebtor()->getDebtor()->setCui('RO14186770');
        $this->em->flush();

        $this->client->request(
            'POST',
            sprintf('/case/%d/claim/invoice', $caseId),
            ['claim_invoice' => ['_token' => $this->token('claim_invoice')]],
            ['claim_invoice' => ['file' => $this->makeFixtureUpload()]],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );
        self::assertResponseIsSuccessful();
        $document = $this->em->getRepository(Document::class)->findOneBy(['legalCase' => $caseId]);
        self::assertInstanceOf(Document::class, $document);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        /** @var ExtractDataMessageHandler $handler */
        $handler = static::getContainer()->get(ExtractDataMessageHandler::class);
        $handler($sent[0]->getMessage());
        $this->em->clear();
        $document = $this->em->find(Document::class, $document->getId());
        self::assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        self::assertSame('pdf_parser', $document->getExtractionStrategy());
        self::assertSame('15193236', $document->getExtractedData()['creditor']['cui']);

        $crawler = $this->client->request('GET', sprintf('/case/%d/claim/invoice/%d', $caseId, $document->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $crawler->filter('#claim-invoice-status-body')->attr('data-claim-invoice-state'));
        self::assertSame('2026-05-31', $crawler->filter('input[name="claim_position[dueDate]"]')->attr('value'), 'The due date the invoice states.');
        $amount = (string) $crawler->filter('input[name="claim_position[amount]"]')->attr('value');
        self::assertGreaterThan(1200.0, (float) $amount);
        self::assertStringContainsString('detectat', $crawler->filter('form[data-testid="claim-position-form-document"]')->text(), 'The fields the reading supplied are tagged.');
        $partyCheck = $crawler->filter('[data-testid="claim-party-check"]')->text();
        self::assertStringContainsString('Emitent', $partyCheck);
        self::assertStringContainsString('Destinatar', $partyCheck);
        self::assertStringNotContainsString('nu a putut fi verificat', $partyCheck, 'Both codes match the case.');
        self::assertSame(2, substr_count($partyCheck, 'corespunde dosarului'));
        self::assertCount(0, $crawler->filter('[data-testid="claim-position-warnings-extracted"]'));

        $posted = [
            '_token' => $this->token('claim_position'),
            'documentNumber' => (string) $crawler->filter('input[name="claim_position[documentNumber]"]')->attr('value'),
            'documentDate' => (string) $crawler->filter('input[name="claim_position[documentDate]"]')->attr('value'),
            'dueDate' => '2026-05-31',
            'amount' => $amount,
            'currency' => 'RON',
            'sourceDocumentId' => (string) $document->getId(),
        ];
        $this->client->request('POST', sprintf('/case/%d/claim/invoice/%d/add', $caseId, $document->getId()), ['claim_position' => $posted], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-claim-card"', $body);
        self::assertStringContainsString('target="case-kpi-grid"', $body);
        self::assertStringContainsString('Factura a fost adăugată în creanță.', $body);

        $this->em->clear();
        $case = $this->em->find(LegalCase::class, $caseId);
        self::assertSame(sprintf('%.2f', 1000.0 + (float) $amount), $case->getAmount());
        $added = $this->em->getRepository(ClaimItem::class)->findOneBy(['legalCase' => $caseId, 'sourceDocument' => $document->getId()]);
        self::assertInstanceOf(ClaimItem::class, $added);
        self::assertSame('2026-05-31', $added->getDueDate()?->format('Y-m-d'));
        self::assertSame($amount, $added->getAmount());
        $entry = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'claim_edited', 'entityId' => (string) $caseId], ['id' => 'DESC']);
        self::assertInstanceOf(AuditLog::class, $entry);
        self::assertSame('add_from_document', $entry->getNewData()['reason']);
        $source = $entry->getOldData()['input']['source'];
        self::assertSame('document', $source['origin']);
        self::assertSame('pdf_parser', $source['extractionStrategy']);
        self::assertStringStartsWith('2026-05-31', (string) $source['extractedClaim']['dueDate'], 'The due date as the parser stored it.');
        self::assertSame('RON', $source['extractedClaim']['currency']);
    }

    private function token(string $tokenId): string
    {
        $this->client->request('GET', '/case/' . $this->case->getId());
        $request = $this->client->getRequest();
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }
        $request->getSession()->save();

        return $token;
    }

    /** The committed realistic invoice, copied because the client moves the upload. */
    private function makeFixtureUpload(): UploadedFile
    {
        $fixture = dirname(__DIR__) . '/fixtures/extraction/invoice-realistic.pdf';
        $tmp = tempnam(sys_get_temp_dir(), 'cascade-real-') . '.pdf';
        copy($fixture, $tmp);

        return new UploadedFile($tmp, 'invoice-realistic.pdf', 'application/pdf', null, true);
    }

    private function makePdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cascade') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% " . uniqid() . "\ntrailer<</Root 1 0 R>>\n%%EOF");

        return new UploadedFile($path, 'factura-F-2025-0210.pdf', 'application/pdf', null, true);
    }
}
