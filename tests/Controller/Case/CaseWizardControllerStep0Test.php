<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Message\ExtractDataMessage;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Integration tests for the Pas 3.0 wizard step 0 controller.
 *
 * We exercise the real HTTP cycle (session, file upload, async message bus,
 * Mercure publisher) against an in-memory transport so the cascade isn't
 * actually invoked but the dispatch contract is verified.
 *
 * Session state across requests is built up using real requests rather than
 * manually injecting session data — the test client maintains the cookie jar
 * between requests, so a POST upload primes session for a subsequent GET.
 */
final class CaseWizardControllerStep0Test extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('wizard-step0-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Wizard');
        $this->user->setLastName('Tester');
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    public function testStartRedirectsToDocumentsStep(): void
    {
        $this->client->request('GET', '/case/new');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects('/case/new/documents');
    }

    public function testStartClearsLeftoverSessionFromPreviousWizardRun(): void
    {
        // Simulate an abandoned wizard: upload one file then walk away.
        $this->uploadOneFile();
        $owned = $this->em->getRepository(Document::class)->findOneBy(['uploadedBy' => $this->user]);
        self::assertNotNull($owned, 'precondition: upload created an orphan Document');

        // Clicking "Dosar nou" again must reset the wizard state so the user
        // lands on an empty drop zone — NOT on the previous abandoned files.
        $this->client->request('GET', '/case/new');
        self::assertResponseRedirects('/case/new/documents');

        // Follow the redirect and confirm the list of documents in the page
        // is empty (the orphan Document row is still in the DB — cleanup is
        // a backlog cron — but it's no longer surfaced in the new wizard run).
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('wizard-upload.pdf', $html);
        self::assertStringNotContainsString('data-document-id="' . $owned->getId() . '"', $html);
        self::assertStringContainsString('Încarcă documente pentru a vedea valorile extrase', $html);
    }

    public function testGetDocumentsRendersDropZoneOnFreshSession(): void
    {
        $this->client->request('GET', '/case/new/documents');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Trage fișierele aici', $html, 'Drop zone heading expected on fresh session');
        // Side-card empty-state hint
        self::assertStringContainsString('Încarcă documente pentru a vedea valorile extrase', $html);
    }

    public function testPostUploadPersistsDocumentAndDispatchesMessage(): void
    {
        $this->uploadOneFile();

        self::assertResponseRedirects('/case/new/documents');

        /** @var DocumentRepository $docRepo */
        $docRepo = static::getContainer()->get(DocumentRepository::class);
        $documents = $docRepo->findBy(['uploadedBy' => $this->user]);
        self::assertCount(1, $documents, 'One document persisted from the upload');
        $doc = $documents[0];
        self::assertNull($doc->getLegalCase(), 'Wizard step 0 must leave legal_case_id NULL');
        self::assertSame(ExtractionStatus::PENDING, $doc->getExtractionStatus());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $msg = $sent[0]->getMessage();
        self::assertInstanceOf(ExtractDataMessage::class, $msg);
        self::assertSame($doc->getId(), $msg->documentId);
    }

    public function testGetDocumentsShowsCompletedDocumentInListAfterReturn(): void
    {
        // 1) Real upload to seed session + create a Document row.
        $this->uploadOneFile();

        // 2) Simulate worker having finished — flip the persisted document to
        // COMPLETED with extractedData populated.
        $doc = $this->em->getRepository(Document::class)->findOneBy(['uploadedBy' => $this->user]);
        self::assertNotNull($doc);
        $this->em->clear(); // Detach so the next find re-reads from DB
        $fresh = $this->em->find(Document::class, $doc->getId());
        $fresh->setOriginalFilename('demo-invoice.pdf');
        $fresh->setExtractionStatus(ExtractionStatus::COMPLETED);
        $fresh->setExtractionConfidence('0.93');
        $fresh->setExtractedData([
            'creditor' => [
                'name' => 'Demo Creditor SRL',
                'cui' => 'RO12345678',
                'confidencePerField' => ['name' => 0.95, 'cui' => 0.99],
            ],
            'debtor' => null,
            'claim' => null,
        ]);
        $this->em->flush();

        // 3) Follow the PRG redirect — GET the documents step again.
        $this->client->request('GET', '/case/new/documents');
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('demo-invoice.pdf', $html);
        self::assertStringContainsString('Demo Creditor SRL', $html);
        self::assertStringContainsString('RO12345678', $html);
    }

    public function testStep0CardShowsTheInvoiceTotalNotOneInvoiceSum(): void
    {
        // Two invoices for the same debtor: the card must show their sum, and
        // the per-invoice sum/due-date/number rows must not sit under the total
        // contradicting it (the failure the lawyer reported).
        $ids = [];
        foreach ([['FF 0036', '2026-02-06', 493.30], ['FF 0038', '2026-03-11', 493.23]] as $i => [$number, $due, $amount]) {
            $doc = new Document();
            $doc->setOriginalFilename('invoice-' . $i . '.pdf');
            $doc->setStoredFilename('seed/' . $i . '.pdf');
            $doc->setFileSize(26000);
            $doc->setMimeType('application/pdf');
            $doc->setDocumentType(DocumentType::FACTURA);
            $doc->setDetectedType(DocumentType::FACTURA);
            $doc->setUploadedBy($this->em->getReference(User::class, $this->user->getId()));
            $doc->setContentHash(hash('sha256', 'invoice-' . $i));
            $doc->setExtractionStatus(ExtractionStatus::COMPLETED);
            $doc->setExtractionConfidence('0.95');
            $doc->setExtractedData([
                'schemaVersion' => 2,
                'creditor' => ['name' => 'Techedge SRL', 'cui' => 'RO49932252', 'confidencePerField' => ['name' => 0.98, 'cui' => 0.97]],
                'debtors' => [['name' => 'LH Consultancy SRL', 'cui' => 'RO44844397', 'confidencePerField' => ['name' => 0.98, 'cui' => 0.97]]],
                'claim' => [
                    'amount' => $amount, 'currency' => 'RON', 'dueDate' => $due . 'T00:00:00+00:00',
                    'legalGround' => 'FACTURA_ACCEPTATA', 'description' => 'Servicii software',
                    'invoiceNumber' => $number, 'invoiceDate' => '2026-01-22T00:00:00+00:00',
                    'confidencePerField' => ['amount' => 0.98, 'currency' => 0.98, 'dueDate' => 0.9, 'legalGround' => 0.85, 'description' => 0.9, 'invoiceNumber' => 0.97, 'invoiceDate' => 0.97],
                ],
            ]);
            $this->em->persist($doc);
            $this->em->flush();
            $ids[] = $doc->getId();
        }

        // Establish the session, then prime the bag with both documents.
        $this->client->request('GET', '/case/new/documents');
        $session = $this->client->getRequest()->getSession();
        $bag = $session->get('case_wizard_data');
        $bag['documentIds'] = $ids;
        $session->set('case_wizard_data', $bag);
        $session->save();

        $this->client->request('GET', '/case/new/documents');
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        // The aggregate is shown: sum of the two invoices.
        self::assertStringContainsString('986,53', $html);
        // The single-invoice figure that used to sit under it is gone.
        self::assertStringNotContainsString('493,30', $html);
        self::assertStringNotContainsString('493.30', $html);
    }

    public function testSkipClearsSessionAndRedirectsToCreditor(): void
    {
        // Establish session via GET, harvest CSRF, POST skip.
        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form[action*="/skip"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/skip', ['_token' => $token]);

        self::assertResponseRedirects('/case/new/creditor');
    }

    public function testCreditorStepRendersOnEmptySession(): void
    {
        $this->client->request('GET', '/case/new/creditor');

        // Pas 3.2 wires the real route — drops the Pas 3.0 501 placeholder.
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Date creditor', $html);
    }

    public function testPostUploadWithTurboFrameHeaderReturnsTurboStream(): void
    {
        // Browser-initiated Turbo form submit sends both the `Turbo-Frame`
        // header (matching the form's data-turbo-frame attribute) and Accept
        // header `text/vnd.turbo-stream.html`. The controller must respond
        // with a stream containing replace + replace + append actions instead
        // of the PRG redirect from the non-Turbo fallback.
        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form input[name="step0_documents[_token]"]')->first()->attr('value');

        $this->client->request(
            'POST',
            '/case/new/documents',
            parameters: ['step0_documents' => ['_token' => $token]],
            files: ['step0_documents' => ['documents' => [$this->makePdfUpload()]]],
            server: [
                'HTTP_TURBO_FRAME' => 'step0-dynamic',
                'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html',
            ],
        );

        self::assertResponseIsSuccessful();
        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $contentType);

        $body = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="step0-dynamic">', $body);
        self::assertStringContainsString('<turbo-stream action="replace" target="step0-sidecard">', $body);
        self::assertStringContainsString('<turbo-stream action="append" target="toasts">', $body);
        // Toast variant on success — must be the emerald palette (not the
        // amber/red used for rate-limit or MIME errors).
        self::assertStringContainsString('bg-emerald-50', $body);
        // Anti-regression: payload must NOT include extracted PII (CNP/IBAN
        // get masked via PiiMaskerExtension in the sidecard partial, but a
        // fresh upload has empty extractedData anyway — confirm no raw blob).
        self::assertStringNotContainsString('"confidencePerField"', $body);
    }

    public function testStatusEndpointReturnsOnlyOwnSessionDocuments(): void
    {
        // Upload to seed session + create owned document.
        $this->uploadOneFile();
        $owned = $this->em->getRepository(Document::class)->findOneBy(['uploadedBy' => $this->user]);
        self::assertNotNull($owned);

        // Persist a document belonging to another user — must not be returned
        // by the status endpoint even when its id is in the query string.
        $other = new User();
        $other->setEmail('other-' . uniqid() . '@test.com');
        $other->setPassword('x');
        $other->setIsVerified(true);
        $other->setFirstName('Other');
        $other->setLastName('User');
        $this->em->persist($other);
        $this->em->flush();
        $alien = new Document();
        $alien->setDocumentType(DocumentType::ALT_DOCUMENT);
        $alien->setOriginalFilename('alien.pdf');
        $alien->setStoredFilename('cases/_pending/9999/alien.pdf');
        $alien->setFileSize(1);
        $alien->setMimeType('application/pdf');
        $alien->setUploadedBy($other);
        $alien->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->persist($alien);
        $this->em->flush();

        $this->client->request('GET', '/case/new/documents/status?ids[]=' . $owned->getId() . '&ids[]=' . $alien->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload);
        self::assertSame($owned->getId(), $payload[0]['documentId']);

        // Cleanup the alien user / document so tearDown order is straightforward.
        $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $other->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM document WHERE id = :id', ['id' => $alien->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $other->getId()]);
    }

    public function testSecondUploadOfTheSameFileIsSkippedAsDuplicate(): void
    {
        $this->uploadOneFile();
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertCount(1, $transport->getSent(), 'precondition: first upload dispatched extraction');

        $this->uploadOneFile();

        $documents = $this->em->getRepository(Document::class)->findBy(['uploadedBy' => $this->user]);
        self::assertCount(1, $documents, 'Identical content must not create a second Document');
        // The transport is per-request in the test kernel, so this counts what
        // the duplicate upload alone dispatched.
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent(), 'Duplicate must not cost a second extraction call');

        $this->client->followRedirect();
        self::assertStringContainsString('Fișier ignorat (wizard-upload.pdf)', $this->client->getResponse()->getContent());
    }

    public function testDuplicateUploadOverTurboReturnsWarningToast(): void
    {
        $this->uploadOneFile();

        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form input[name="step0_documents[_token]"]')->first()->attr('value');
        $this->client->request(
            'POST',
            '/case/new/documents',
            parameters: ['step0_documents' => ['_token' => $token]],
            files: ['step0_documents' => ['documents' => [$this->makePdfUpload()]]],
            server: [
                'HTTP_TURBO_FRAME' => 'step0-dynamic',
                'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html',
            ],
        );

        self::assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        self::assertStringContainsString('bg-amber-50', $body, 'Duplicate is a warning, not a success');
        self::assertStringContainsString('Fișier ignorat (wizard-upload.pdf)', $body);
    }

    public function testMixedBatchStoresOnlyTheNewFile(): void
    {
        $this->uploadOneFile();

        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form input[name="step0_documents[_token]"]')->first()->attr('value');
        $this->client->request(
            'POST',
            '/case/new/documents',
            parameters: ['step0_documents' => ['_token' => $token]],
            files: ['step0_documents' => ['documents' => [
                $this->makePdfUpload(),
                $this->makePdfUpload('another-file.pdf', "\n%% variant\n"),
            ]]],
        );

        $documents = $this->em->getRepository(Document::class)->findBy(['uploadedBy' => $this->user]);
        self::assertCount(2, $documents, 'Only the new file of the batch is stored');
        $names = array_map(static fn (Document $d) => $d->getOriginalFilename(), $documents);
        sort($names);
        self::assertSame(['another-file.pdf', 'wizard-upload.pdf'], $names);

        // Per-request transport: only the new file of the second batch was queued.
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertCount(1, $transport->getSent());
    }

    public function testTwoIdenticalFilesInOneBatchStoreOnlyOne(): void
    {
        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form input[name="step0_documents[_token]"]')->first()->attr('value');
        $this->client->request(
            'POST',
            '/case/new/documents',
            parameters: ['step0_documents' => ['_token' => $token]],
            files: ['step0_documents' => ['documents' => [
                $this->makePdfUpload(),
                $this->makePdfUpload('copy-of-invoice.pdf'),
            ]]],
        );

        $documents = $this->em->getRepository(Document::class)->findBy(['uploadedBy' => $this->user]);
        self::assertCount(1, $documents);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertCount(1, $transport->getSent());
    }

    /**
     * A frame-scoped submission makes Turbo keep only the matching frame of the
     * response and silently drop every stream aimed elsewhere, so the outcome
     * toasts (duplicate skipped, rate limit, rejected file) never reached the
     * page. The upload form must therefore stay unscoped.
     */
    public function testUploadFormIsNotScopedToATurboFrame(): void
    {
        $crawler = $this->client->request('GET', '/case/new/documents');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="step0_documents"]');
        self::assertCount(1, $form);
        self::assertNull($form->attr('data-turbo-frame'));
    }

    public function testReuploadIsAllowedAfterTheDocumentWasDeleted(): void
    {
        $this->uploadOneFile();
        $first = $this->em->getRepository(Document::class)->findOneBy(['uploadedBy' => $this->user]);
        self::assertNotNull($first);

        // Deletion is a hard delete, so the fingerprint leaves the draft with
        // the row: the same file must be uploadable again afterwards.
        $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type = :t AND entity_id = :id', [
            't' => 'Document',
            'id' => (string) $first->getId(),
        ]);
        $this->em->getConnection()->executeStatement('DELETE FROM document WHERE id = :id', ['id' => $first->getId()]);
        $this->em->clear();

        $this->uploadOneFile();

        $documents = $this->em->getRepository(Document::class)->findBy(['uploadedBy' => $this->user]);
        self::assertCount(1, $documents, 'Re-upload after deletion must create a fresh Document');
        self::assertNotSame($first->getId(), $documents[0]->getId());
    }

    /**
     * Drives the wizard step-0 POST with a single PDF attached. We GO around
     * the DomCrawler form-builder because it can't auto-discover an sr-only
     * file input nested in a label wrapper. The direct $client->request()
     * with the $files array is the canonical Symfony pattern for multipart
     * uploads in tests.
     */
    private function uploadOneFile(?DocumentType $declaredType = null): void
    {
        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form input[name="step0_documents[_token]"]')->first()->attr('value');

        $parameters = ['_token' => $token];
        if ($declaredType !== null) {
            $parameters['documentType'] = $declaredType->value;
        }

        $this->client->request(
            'POST',
            '/case/new/documents',
            parameters: ['step0_documents' => $parameters],
            files: ['step0_documents' => ['documents' => [$this->makePdfUpload()]]],
        );
    }

    private function makePdfUpload(string $clientName = 'wizard-upload.pdf', string $contentSuffix = ''): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wizard-pdf-');
        // Minimal but well-formed PDF so DocumentUploadService's finfo MIME
        // sniff classifies it as application/pdf.
        file_put_contents(
            $tmp,
            "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\n"
            . "xref\n0 3\n0000000000 65535 f\n0000000010 00000 n\n0000000060 00000 n\n"
            . "trailer<</Size 3/Root 1 0 R>>\nstartxref\n110\n%%EOF\n"
            . $contentSuffix,
        );

        return new UploadedFile($tmp, $clientName, 'application/pdf', UPLOAD_ERR_OK, true);
    }

    // ---------- declaring and correcting the document type ----------

    public function testTheUploadFormOffersTheDocumentTypes(): void
    {
        $crawler = $this->client->request('GET', '/case/new/documents');

        $options = $crawler->filter('select[name="step0_documents[documentType]"] option')
            ->each(static fn ($node) => $node->attr('value'));

        self::assertContains(DocumentType::FACTURA->value, $options);
        self::assertContains(DocumentType::EXTRAS_CONT->value, $options);
        // The auto-detect entry, which is the default and stores the wizard's
        // placeholder type.
        self::assertSame(DocumentType::ALT_DOCUMENT->value, $options[0]);
        // Generated filings are not uploadable and must not be offered.
        self::assertNotContains(DocumentType::CERERE_OP->value, $options);
    }

    public function testADeclaredTypeIsStoredOnTheUploadedDocument(): void
    {
        $this->uploadOneFile(DocumentType::FACTURA);

        /** @var DocumentRepository $docRepo */
        $docRepo = static::getContainer()->get(DocumentRepository::class);
        $documents = $docRepo->findBy(['uploadedBy' => $this->user]);

        self::assertCount(1, $documents);
        self::assertSame(DocumentType::FACTURA, $documents[0]->getDocumentType());
    }

    public function testNoDeclaredTypeLeavesThePlaceholderForTheClassifier(): void
    {
        $this->uploadOneFile();

        /** @var DocumentRepository $docRepo */
        $docRepo = static::getContainer()->get(DocumentRepository::class);
        $documents = $docRepo->findBy(['uploadedBy' => $this->user]);

        self::assertSame(DocumentType::ALT_DOCUMENT, $documents[0]->getDocumentType());
    }

    public function testCorrectingTheTypeRequeuesTheExtraction(): void
    {
        $this->uploadOneFile();
        /** @var DocumentRepository $docRepo */
        $docRepo = static::getContainer()->get(DocumentRepository::class);
        $document = $docRepo->findBy(['uploadedBy' => $this->user])[0];
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form[action$="/type"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/documents/' . $document->getId() . '/type', [
            '_token' => $token,
            'documentType' => DocumentType::CONTRACT->value,
        ]);

        self::assertResponseRedirects('/case/new/documents');
        // Refetched rather than refreshed: the kernel reboots between requests,
        // so the instance held here is detached.
        $reloaded = $docRepo->find($document->getId());
        self::assertSame(DocumentType::CONTRACT, $reloaded->getDocumentType());
        // Reprocessing is the point of the correction: the document is read
        // again, this time with the instructions written for a contract.
        self::assertSame(ExtractionStatus::PENDING, $reloaded->getExtractionStatus());
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame($document->getId(), $sent[0]->getMessage()->documentId);
    }

    public function testCorrectingTheTypeRejectsAGeneratedType(): void
    {
        $this->uploadOneFile();
        /** @var DocumentRepository $docRepo */
        $docRepo = static::getContainer()->get(DocumentRepository::class);
        $document = $docRepo->findBy(['uploadedBy' => $this->user])[0];

        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form[action$="/type"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/documents/' . $document->getId() . '/type', [
            '_token' => $token,
            'documentType' => DocumentType::CERERE_OP->value,
        ]);

        self::assertSame(DocumentType::ALT_DOCUMENT, $docRepo->find($document->getId())->getDocumentType());
    }

    public function testCorrectingTheTypeRejectsATypeWithItsOwnUploadFlow(): void
    {
        // The stamp-duty proof carries payment state that only the stamp-duty
        // controller records, which is why the dropdown never offers it. The
        // route has to refuse it too, or a form post produces a proof of
        // payment with no payment behind it.
        $this->uploadOneFile();
        /** @var DocumentRepository $docRepo */
        $docRepo = static::getContainer()->get(DocumentRepository::class);
        $document = $docRepo->findBy(['uploadedBy' => $this->user])[0];

        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form[action$="/type"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/documents/' . $document->getId() . '/type', [
            '_token' => $token,
            'documentType' => DocumentType::DOVADA_TAXA_TIMBRU->value,
        ]);

        self::assertSame(DocumentType::ALT_DOCUMENT, $docRepo->find($document->getId())->getDocumentType());
    }

    public function testCorrectingTheTypeRequiresAValidToken(): void
    {
        $this->uploadOneFile();
        /** @var DocumentRepository $docRepo */
        $docRepo = static::getContainer()->get(DocumentRepository::class);
        $document = $docRepo->findBy(['uploadedBy' => $this->user])[0];

        $this->client->request('POST', '/case/new/documents/' . $document->getId() . '/type', [
            '_token' => 'wrong',
            'documentType' => DocumentType::CONTRACT->value,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
