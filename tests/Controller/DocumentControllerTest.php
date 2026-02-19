<?php

namespace App\Tests\Controller;

use App\Entity\Court;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DocumentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private User $otherUser;
    private Court $court;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->court = new Court();
        $this->court->setName('Judecătoria DocTest');
        $this->court->setCounty('Test');
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setActive(true);
        $this->em->persist($this->court);

        $this->user = new User();
        $this->user->setEmail('doc-test-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Test');
        $this->user->setLastName('User');
        $this->em->persist($this->user);

        $this->otherUser = new User();
        $this->otherUser->setEmail('doc-other-' . uniqid() . '@test.com');
        $this->otherUser->setPassword($hasher->hashPassword($this->otherUser, 'password'));
        $this->otherUser->setIsVerified(true);
        $this->otherUser->setFirstName('Other');
        $this->otherUser->setLastName('User');
        $this->em->persist($this->otherUser);

        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    private function createCaseWithPdf(): array
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus('pending_payment');
        $case->setCurrentStep(6);
        $case->setCounty('Test');
        $case->setCourt($this->court);
        $case->setClaimantType('pf');
        $case->setClaimantData(['name' => 'Test', 'email' => 'test@test.com', 'city' => 'Test', 'county' => 'Test']);
        $case->setDefendants([['type' => 'pf', 'name' => 'Defendant', 'city' => 'Test', 'county' => 'Test']]);
        $case->setClaimAmount('1000.00');
        $case->setClaimDescription('Test');
        $case->setInterestType('none');
        $this->em->persist($case);
        $this->em->flush();

        // Create a test PDF file
        $dir = $this->uploadsDir . '/cases/' . $case->getId();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $storedFilename = 'cases/' . $case->getId() . '/cerere_' . $case->getId() . '.pdf';
        file_put_contents($this->uploadsDir . '/' . $storedFilename, '%PDF-1.4 test content');

        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::CERERE_PDF);
        $document->setOriginalFilename('Cerere #' . $case->getId() . '.pdf');
        $document->setStoredFilename($storedFilename);
        $document->setFileSize(21);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->user);
        $this->em->persist($document);
        $this->em->flush();

        return [$case, $document];
    }

    public function testDownloadOwnDocument(): void
    {
        [$case, $document] = $this->createCaseWithPdf();

        $this->client->request('GET', "/case/{$case->getId()}/document/{$document->getId()}/download");

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/pdf');
    }

    public function testDownloadOtherUserDocumentDenied(): void
    {
        [$case, $document] = $this->createCaseWithPdf();

        // Login as other user
        $this->client->loginUser($this->otherUser);
        $this->client->request('GET', "/case/{$case->getId()}/document/{$document->getId()}/download");

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDownloadNonExistentDocumentReturns404(): void
    {
        [$case] = $this->createCaseWithPdf();

        $this->client->request('GET', "/case/{$case->getId()}/document/99999/download");

        $this->assertResponseStatusCodeSame(404);
    }

    // =========================================================================
    // Upload tests
    // =========================================================================

    public function testUploadValidPdf(): void
    {
        [$case] = $this->createCaseWithPdf();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.pdf';
        file_put_contents($tempFile, '%PDF-1.4 upload test');

        // Load the view page and find the upload form
        $crawler = $this->client->request('GET', "/case/{$case->getId()}");
        $form = $crawler->selectButton('Încarcă')->form();
        $form['document_upload[documentType]']->select('factura');
        $form['document_upload[file]']->upload($tempFile);
        $this->client->submit($form);

        $this->assertResponseRedirects("/case/{$case->getId()}");

        // Verify document was created
        $this->em->clear();
        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $case->getId()]);
        // Should have 2: the original CERERE_PDF + the uploaded FACTURA
        $this->assertCount(2, $documents);

        $uploaded = array_values(array_filter($documents, fn($d) => $d->getDocumentType() === DocumentType::FACTURA));
        $this->assertCount(1, $uploaded);
        $this->assertNotEmpty($uploaded[0]->getMimeType());

        // Verify file on disk
        $filePath = $this->uploadsDir . '/' . $uploaded[0]->getStoredFilename();
        $this->assertFileExists($filePath);
    }

    public function testUploadAsOtherUserDenied(): void
    {
        [$case] = $this->createCaseWithPdf();

        $this->client->loginUser($this->otherUser);

        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.pdf';
        file_put_contents($tempFile, '%PDF-1.4 test');
        $uploadedFile = new UploadedFile($tempFile, 'test.pdf', 'application/pdf', null, true);

        $this->client->request('POST', "/case/{$case->getId()}/document/upload", [
            'document_upload' => ['documentType' => 'dovada'],
        ], [
            'document_upload' => ['file' => $uploadedFile],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUploadWhenMaxFilesReached(): void
    {
        [$case] = $this->createCaseWithPdf();

        // Create 9 more documents to reach limit of 10
        for ($i = 0; $i < 9; $i++) {
            $doc = new Document();
            $doc->setLegalCase($case);
            $doc->setDocumentType(DocumentType::DOVADA);
            $doc->setOriginalFilename("dovada_{$i}.pdf");
            $doc->setStoredFilename("cases/{$case->getId()}/dovada_{$i}.pdf");
            $doc->setFileSize(100);
            $doc->setMimeType('application/pdf');
            $doc->setUploadedBy($this->user);
            $this->em->persist($doc);
        }
        $this->em->flush();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.pdf';
        file_put_contents($tempFile, '%PDF-1.4 test');
        $uploadedFile = new UploadedFile($tempFile, 'extra.pdf', 'application/pdf', null, true);

        $this->client->request('POST', "/case/{$case->getId()}/document/upload", [
            'document_upload' => ['documentType' => 'dovada'],
        ], [
            'document_upload' => ['file' => $uploadedFile],
        ]);

        $this->assertResponseRedirects("/case/{$case->getId()}");
    }

    // =========================================================================
    // Delete tests
    // =========================================================================

    public function testDeleteOwnDocument(): void
    {
        [$case] = $this->createCaseWithPdf();

        // Create a user-uploaded document (not CERERE_PDF)
        $storedFilename = 'cases/' . $case->getId() . '/user_doc.pdf';
        file_put_contents($this->uploadsDir . '/' . $storedFilename, '%PDF-1.4 user doc');

        $doc = new Document();
        $doc->setLegalCase($case);
        $doc->setDocumentType(DocumentType::DOVADA);
        $doc->setOriginalFilename('dovada_mea.pdf');
        $doc->setStoredFilename($storedFilename);
        $doc->setFileSize(17);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $this->em->persist($doc);
        $this->em->flush();

        $docId = $doc->getId();
        $caseId = $case->getId();

        // Clear EM so controller gets fresh entities with updated documents collection
        $this->em->clear();

        // Load the view page (after document was created) — find the delete form
        $crawler = $this->client->request('GET', "/case/{$caseId}");
        $deleteForms = $crawler->filter("form[action\$=\"/document/{$docId}/delete\"]");
        $this->assertGreaterThan(0, $deleteForms->count(), 'Delete form not found for document');
        $this->client->submit($deleteForms->form());

        $this->assertResponseRedirects("/case/{$caseId}");

        // Verify document was removed
        $this->em->clear();
        $deleted = $this->em->getRepository(Document::class)->find($docId);
        $this->assertNull($deleted);

        // Verify file removed from disk
        $this->assertFileDoesNotExist($this->uploadsDir . '/' . $storedFilename);
    }

    public function testDeleteCererePdfProtected(): void
    {
        [$case, $cerereDoc] = $this->createCaseWithPdf();

        // CERERE_PDF shouldn't have a delete form on the page, so submit manually with CSRF
        // Load the page to init session
        $crawler = $this->client->request('GET', "/case/{$case->getId()}");

        // Extract any CSRF token from the page (reuse from another form)
        $token = $crawler->filter('input[name="document_upload[_token]"]')->attr('value');

        $this->client->request('POST', "/case/{$case->getId()}/document/{$cerereDoc->getId()}/delete", [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects("/case/{$case->getId()}");

        // CERERE_PDF should still exist
        $this->em->clear();
        $stillExists = $this->em->getRepository(Document::class)->find($cerereDoc->getId());
        $this->assertNotNull($stillExists);
    }

    public function testDeleteAsOtherUserDenied(): void
    {
        [$case] = $this->createCaseWithPdf();

        $doc = new Document();
        $doc->setLegalCase($case);
        $doc->setDocumentType(DocumentType::DOVADA);
        $doc->setOriginalFilename('dovada.pdf');
        $doc->setStoredFilename('cases/' . $case->getId() . '/dovada.pdf');
        $doc->setFileSize(100);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $this->em->persist($doc);
        $this->em->flush();

        $this->client->loginUser($this->otherUser);
        $this->client->request('POST', "/case/{$case->getId()}/document/{$doc->getId()}/delete", [
            '_token' => 'fake-token',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();

        foreach ([$this->user, $this->otherUser] as $u) {
            $uid = $u->getId();
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$uid]);
            $conn->executeStatement('DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id WHERE lc.user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM payment WHERE user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM user WHERE id = ?', [$uid]);
        }
        $conn->executeStatement('DELETE FROM court WHERE id = ?', [$this->court->getId()]);

        // Clean up test files
        $casesDir = $this->uploadsDir . '/cases';
        if (is_dir($casesDir)) {
            foreach (glob($casesDir . '/*', GLOB_ONLYDIR) as $dir) {
                foreach (glob($dir . '/*') as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }
}
