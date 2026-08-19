<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for DocumentController (upload / download / delete).
 *
 * Documents here are pure attachments (court evidence) — no AI extraction.
 * Upload/delete respond with a Turbo Stream for Turbo clients and a redirect
 * (tab preserved) otherwise.
 */
final class DocumentControllerTest extends WebTestCase
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
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('doc-ctrl-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Doc');
        $this->user->setLastName('Owner');
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setAmount('5000.00');
        $this->case->setCurrency('RON');
        $this->case->setDueDate(new \DateTime('2024-03-15'));
        $this->em->persist($this->case);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id IS NULL', []);
        $conn->executeStatement(
            'DELETE d FROM document d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement(
            'DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement(
            'DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        // Remove on-disk files for this case before dropping the row.
        $caseDir = $this->uploadsDir . '/cases/' . $this->case->getId();
        if (is_dir($caseDir)) {
            foreach (glob($caseDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($caseDir);
        }
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);

        parent::tearDown();
    }

    /** Persists a Document row directly (no file on disk needed for cap counting). */
    private function persistDocument(DocumentType $type): void
    {
        $doc = new Document();
        $doc->setLegalCase($this->case);
        $doc->setDocumentType($type);
        $doc->setOriginalFilename($type->value . '.pdf');
        $doc->setStoredFilename('cases/' . $this->case->getId() . '/' . uniqid() . '.pdf');
        $doc->setFileSize(1024);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $this->em->persist($doc);
        $this->em->flush();
    }

    /** Minimal valid PDF written to a temp file, wrapped as a test UploadedFile. */
    private function makePdf(string $name = 'dovada.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function uploadToken(): string
    {
        $this->client->request('GET', '/case/' . $this->case->getId());

        return (string) $this->client->getCrawler()
            ->filter('input[name="document_upload[_token]"]')->first()->attr('value');
    }

    private function deleteToken(int $documentId): string
    {
        $this->client->request('GET', '/case/' . $this->case->getId());
        $token = '';
        $this->client->getCrawler()->filter('form[action*="/document/"]')->each(function ($form) use ($documentId, &$token) {
            if (preg_match('#/document/(\d+)/delete#', (string) $form->attr('action'), $m) && (int) $m[1] === $documentId) {
                $token = (string) $form->filter('input[name="_token"]')->attr('value');
            }
        });

        return $token;
    }

    /** Uploads a document through the real endpoint and returns its id. */
    private function uploadDocument(string $type = 'dovada'): int
    {
        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $this->uploadToken(), 'documentType' => $type]],
            ['document_upload' => ['file' => $this->makePdf()]],
        );

        $this->em->clear();
        $doc = $this->em->getRepository(Document::class)->findOneBy(['legalCase' => $this->case->getId()]);
        self::assertNotNull($doc, 'Upload should persist a document.');

        return $doc->getId();
    }

    // ===== upload =======================================================

    public function testUploadHappyPathPersistsDocumentAndRedirects(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $this->uploadToken(), 'documentType' => 'contract']],
            ['document_upload' => ['file' => $this->makePdf('contract.pdf')]],
        );

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=documente');

        $this->em->clear();
        $docs = $this->em->getRepository(Document::class)->findBy(['legalCase' => $this->case->getId()]);
        self::assertCount(1, $docs);
        self::assertSame(DocumentType::CONTRACT, $docs[0]->getDocumentType());
        self::assertSame('contract.pdf', $docs[0]->getOriginalFilename());
    }

    public function testUploadReturnsTurboStreamWhenRequested(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $this->uploadToken(), 'documentType' => 'dovada_comunicare']],
            ['document_upload' => ['file' => $this->makePdf()]],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'text/vnd.turbo-stream.html',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="panel-documente"', $body);
        self::assertStringContainsString('target="case-tabs-nav"', $body);
        self::assertStringContainsString('close-modal', $body);
        self::assertStringContainsString('target="toasts"', $body);
    }

    public function testUploadWithoutFileReturnsErrorToastStream(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $this->uploadToken(), 'documentType' => 'dovada']],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // Error path: toast only, no region update, no modal close (stays open to fix).
        self::assertStringContainsString('target="toasts"', $body);
        self::assertStringNotContainsString('target="panel-documente"', $body);
        self::assertStringNotContainsString('close-modal', $body);

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Document::class)->findBy(['legalCase' => $this->case->getId()]));
    }

    public function testUploadRejectedWhenSourceAttachmentCapReached(): void
    {
        $this->client->loginUser($this->user);
        // 10 source attachments fill the cap.
        for ($i = 0; $i < 10; ++$i) {
            $this->persistDocument(DocumentType::DOVADA);
        }

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $this->uploadToken(), 'documentType' => 'contract']],
            ['document_upload' => ['file' => $this->makePdf()]],
        );

        $this->em->clear();
        self::assertSame(10, $this->em->getRepository(Document::class)->count(['legalCase' => $this->case->getId()]), 'Upload past the cap must be rejected.');
    }

    /**
     * The proof of communication gates the petition (CPC art. 1015 para. 1), so a case
     * whose quota is filled with invoices must still be able to attach it. Otherwise a
     * product limit would become a procedural dead end: no proof, no filing, ever.
     */
    public function testTheCommunicationProofIsAcceptedEvenWithTheCapReached(): void
    {
        $this->client->loginUser($this->user);
        for ($i = 0; $i < 10; ++$i) {
            $this->persistDocument(DocumentType::FACTURA);
        }

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $this->uploadToken(), 'documentType' => 'dovada_comunicare']],
            ['document_upload' => ['file' => $this->makePdf('dovada-comunicare.pdf')]],
        );

        $this->em->clear();
        $proofs = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $this->case->getId(),
            'documentType' => DocumentType::DOVADA_COMUNICARE,
        ]);
        self::assertCount(1, $proofs, 'The cap must never stand between a case and the proof its filing needs.');
    }

    /**
     * The exemption cuts both ways: the two procedural proofs do not consume the quota
     * either, so a case carrying them keeps all ten slots for its evidence.
     */
    public function testProceduralProofsDoNotConsumeAttachmentCap(): void
    {
        $this->client->loginUser($this->user);
        $this->persistDocument(DocumentType::DOVADA_COMUNICARE);
        $this->persistDocument(DocumentType::DOVADA_TAXA_TIMBRU);
        for ($i = 0; $i < 9; ++$i) {
            $this->persistDocument(DocumentType::FACTURA);
        }

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $this->uploadToken(), 'documentType' => 'contract']],
            ['document_upload' => ['file' => $this->makePdf('contract.pdf')]],
        );

        $this->em->clear();
        $contracts = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $this->case->getId(),
            'documentType' => DocumentType::CONTRACT,
        ]);
        self::assertCount(1, $contracts, 'Nine invoices plus the two proofs leave a slot free.');
    }

    public function testGeneratedDocumentsDoNotConsumeAttachmentCap(): void
    {
        $this->client->loginUser($this->user);
        // 3 application-generated documents must NOT count against the attachment cap.
        $this->persistDocument(DocumentType::SOMATIE);
        $this->persistDocument(DocumentType::CERERE_OP);
        $this->persistDocument(DocumentType::OPIS);

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $this->uploadToken(), 'documentType' => 'contract']],
            ['document_upload' => ['file' => $this->makePdf('contract.pdf')]],
        );

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=documente');

        $this->em->clear();
        $contracts = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $this->case->getId(),
            'documentType' => DocumentType::CONTRACT,
        ]);
        self::assertCount(1, $contracts, 'Generated documents must not block a source upload.');
    }

    public function testUploadRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => 'invalid-token', 'documentType' => 'dovada']],
            ['document_upload' => ['file' => $this->makePdf()]],
        );

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=documente');

        $this->em->clear();
        self::assertCount(
            0,
            $this->em->getRepository(Document::class)->findBy(['legalCase' => $this->case->getId()]),
            'Invalid CSRF token must reject the upload.',
        );
    }

    public function testUploadForbiddenForOtherUser(): void
    {
        $intruder = $this->createIntruder();
        try {
            $this->client->loginUser($intruder);
            $this->client->request(
                'POST',
                sprintf('/case/%d/document/upload', $this->case->getId()),
                ['document_upload' => ['_token' => 'any', 'documentType' => 'dovada']],
                ['document_upload' => ['file' => $this->makePdf()]],
            );
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
    }

    // ===== download =====================================================

    public function testDownloadReturnsFileAttachment(): void
    {
        $this->client->loginUser($this->user);
        $docId = $this->uploadDocument();

        $this->client->request('GET', sprintf('/case/%d/document/%d/download', $this->case->getId(), $docId));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testDownloadForbiddenForOtherUser(): void
    {
        $this->client->loginUser($this->user);
        $docId = $this->uploadDocument();

        $intruder = $this->createIntruder();
        try {
            $this->client->loginUser($intruder);
            $this->client->request('GET', sprintf('/case/%d/document/%d/download', $this->case->getId(), $docId));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
    }

    // ===== delete =======================================================

    public function testDeleteHappyPathRemovesDocument(): void
    {
        $this->client->loginUser($this->user);
        $docId = $this->uploadDocument();

        $this->client->request('POST', sprintf('/case/%d/document/%d/delete', $this->case->getId(), $docId), [
            '_token' => $this->deleteToken($docId),
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=documente');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Document::class)->find($docId));
    }

    /**
     * The stamp-duty proof decides what the petition claims about the payment. Deleting
     * it would leave the case reading as paid with no proof, which the petition then
     * describes as a payment made through the electronic registry: a statement to the
     * court about a route nobody took.
     */
    public function testDeleteRefusesTheStampDutyProof(): void
    {
        $this->client->loginUser($this->user);

        $proof = new Document();
        $proof->setLegalCase($this->case);
        $proof->setDocumentType(DocumentType::DOVADA_TAXA_TIMBRU);
        $proof->setOriginalFilename('dovada.pdf');
        $proof->setStoredFilename('cases/' . $this->case->getId() . '/dovada.pdf');
        $proof->setFileSize(100);
        $proof->setMimeType('application/pdf');
        $proof->setUploadedBy($this->user);
        $this->em->persist($proof);
        $this->em->flush();
        $proofId = $proof->getId();

        $this->client->request('POST', sprintf('/case/%d/document/%d/delete', $this->case->getId(), $proofId), [
            '_token' => $this->deleteToken($proofId),
        ]);

        $this->em->clear();
        self::assertNotNull(
            $this->em->getRepository(Document::class)->find($proofId),
            'The stamp-duty proof must survive a delete attempt from the generic flow.',
        );
    }

    public function testDeleteReturnsTurboStreamWhenRequested(): void
    {
        $this->client->loginUser($this->user);
        $docId = $this->uploadDocument();

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/%d/delete', $this->case->getId(), $docId),
            ['_token' => $this->deleteToken($docId)],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="panel-documente"', $body);
        self::assertStringContainsString('target="toasts"', $body);
    }

    public function testDeleteRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);
        $docId = $this->uploadDocument();

        $this->client->request('POST', sprintf('/case/%d/document/%d/delete', $this->case->getId(), $docId), [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=documente');

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Document::class)->find($docId), 'Invalid CSRF must not delete.');
    }

    private function createIntruder(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('doc-intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Doc');
        $intruder->setLastName('Intruder');
        $this->em->persist($intruder);
        $this->em->flush();

        return $intruder;
    }
}
