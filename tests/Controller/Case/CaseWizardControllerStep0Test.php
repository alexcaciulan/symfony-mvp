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

    /**
     * Drives the wizard step-0 POST with a single PDF attached. We GO around
     * the DomCrawler form-builder because it can't auto-discover an sr-only
     * file input nested in a label wrapper. The direct $client->request()
     * with the $files array is the canonical Symfony pattern for multipart
     * uploads in tests.
     */
    private function uploadOneFile(): void
    {
        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form input[name="step0_documents[_token]"]')->first()->attr('value');

        $this->client->request(
            'POST',
            '/case/new/documents',
            parameters: ['step0_documents' => ['_token' => $token]],
            files: ['step0_documents' => ['documents' => [$this->makePdfUpload()]]],
        );
    }

    private function makePdfUpload(): UploadedFile
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
            . "trailer<</Size 3/Root 1 0 R>>\nstartxref\n110\n%%EOF\n",
        );

        return new UploadedFile($tmp, 'wizard-upload.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
    }
}
