<?php

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Service\AuditLogService;
use App\Service\Document\DocumentUploadService;
use App\Service\Document\FileContentHasher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DocumentUploadServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentUploadService $service;
    private User $user;
    private string $testPrefix;
    private string $uploadsDir;

    /** @var list<string> directories this test made under the pending area, removed on teardown */
    private array $pendingDirs = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(DocumentUploadService::class);
        $this->uploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->testPrefix = 'docupload-test-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus(CaseStatus::AMIABIL);
        $case->setCurrency('RON');
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function createTempUploadedFile(): UploadedFile
    {
        // The service sniffs the MIME server-side and only accepts PDF/images, so
        // upload a real PDF. Copy the fixture to a temp file first because the
        // service moves the uploaded file, which would otherwise consume the fixture.
        $source = \dirname(__DIR__) . '/fixtures/extraction/loan-individual.pdf';
        $tempFile = tempnam(sys_get_temp_dir(), 'test_upload_') . '.pdf';
        copy($source, $tempFile);

        return new UploadedFile($tempFile, 'test-document.pdf', 'application/pdf', null, true);
    }

    public function testUploadCreatesDocumentEntity(): void
    {
        $case = $this->createCase();
        $file = $this->createTempUploadedFile();

        $document = $this->service->upload($case, $file, DocumentType::DOVADA, $this->user);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertNotNull($document->getId());
        $this->assertSame('test-document.pdf', $document->getOriginalFilename());
        $this->assertSame(DocumentType::DOVADA, $document->getDocumentType());
    }

    public function testUploadCreatesAuditLog(): void
    {
        $case = $this->createCase();
        $file = $this->createTempUploadedFile();

        $document = $this->service->upload($case, $file, DocumentType::DOVADA, $this->user);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => 'Document',
            'entityId' => (string) $document->getId(),
            'action' => 'document_upload',
        ]);
        $this->assertNotEmpty($logs);
        $this->assertSame('test-document.pdf', $logs[0]->getNewData()['originalFilename']);
    }

    public function testDeleteCreatesAuditLog(): void
    {
        $case = $this->createCase();
        $file = $this->createTempUploadedFile();
        $document = $this->service->upload($case, $file, DocumentType::DOVADA, $this->user);
        $documentId = (string) $document->getId();

        $this->service->delete($document);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => 'Document',
            'entityId' => $documentId,
            'action' => 'document_delete',
        ]);
        $this->assertNotEmpty($logs);
    }

    public function testDeleteRemovesDocumentEntity(): void
    {
        $case = $this->createCase();
        $file = $this->createTempUploadedFile();
        $document = $this->service->upload($case, $file, DocumentType::DOVADA, $this->user);
        $documentId = $document->getId();

        $this->service->delete($document);

        $found = $this->em->getRepository(Document::class)->find($documentId);
        $this->assertNull($found);
    }

    public function testUploadStampsSha256OfTheStoredFile(): void
    {
        $case = $this->createCase();
        $file = $this->createTempUploadedFile();

        $document = $this->service->upload($case, $file, DocumentType::DOVADA, $this->user);

        $storedPath = $this->uploadsDir . '/' . $document->getStoredFilename();
        $this->assertFileExists($storedPath);
        $this->assertSame(hash_file('sha256', $storedPath), $document->getContentHash());
        $this->assertSame(64, \strlen((string) $document->getContentHash()));
    }

    public function testSameContentUploadedUnderDifferentNamesGivesTheSameHash(): void
    {
        $case = $this->createCase();

        $first = $this->service->upload($case, $this->createTempUploadedFile(), DocumentType::DOVADA, $this->user);

        $renamed = $this->createTempUploadedFile();
        $renamedUpload = new UploadedFile($renamed->getPathname(), 'other-name.pdf', 'application/pdf', null, true);
        $second = $this->service->upload($case, $renamedUpload, DocumentType::DOVADA, $this->user);

        $this->assertNotSame($first->getOriginalFilename(), $second->getOriginalFilename());
        $this->assertSame($first->getContentHash(), $second->getContentHash());
    }

    public function testDifferentContentGivesDifferentHash(): void
    {
        $case = $this->createCase();

        $first = $this->service->upload($case, $this->createTempUploadedFile(), DocumentType::DOVADA, $this->user);

        $otherSource = \dirname(__DIR__) . '/fixtures/extraction/loan-individual.pdf';
        $otherPath = tempnam(sys_get_temp_dir(), 'test_upload_') . '.pdf';
        copy($otherSource, $otherPath);
        // Appending a PDF comment keeps the file a valid PDF for the MIME sniff
        // while changing the bytes, which is exactly what the hash must notice.
        file_put_contents($otherPath, "\n%% altered\n", FILE_APPEND);
        $second = $this->service->upload(
            $case,
            new UploadedFile($otherPath, 'altered.pdf', 'application/pdf', null, true),
            DocumentType::DOVADA,
            $this->user,
        );

        $this->assertNotSame($first->getContentHash(), $second->getContentHash());
    }

    public function testDeletingAPendingUploadRemovesTheRowTheFileAndItsDirectoryAndNamesTheActor(): void
    {
        $document = $this->createPendingDocument();
        $documentId = $document->getId();
        $path = $this->uploadsDir . '/' . $document->getStoredFilename();
        $directory = dirname($path);
        $this->assertFileExists($path);

        $this->service->delete($document, $this->user);

        $this->assertNull($this->em->getRepository(Document::class)->find($documentId));
        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryDoesNotExist($directory, 'the per-upload directory goes with its only file');
        $this->assertDirectoryExists(dirname($directory), 'the user\'s pending area stays');
        $logs = $this->em->getRepository(AuditLog::class)->findBy(['entityType' => 'Document', 'entityId' => (string) $documentId, 'action' => 'document_delete']);
        $this->assertCount(1, $logs);
        $this->assertSame($this->user->getId(), $logs[0]->getUser()?->getId(), 'with no session, the actor passed in is the one recorded');
        $this->assertSame('pending.pdf', $logs[0]->getOldData()['originalFilename']);
        $this->assertSame(DocumentType::FACTURA->value, $logs[0]->getOldData()['documentType']);
        $this->assertNull($logs[0]->getNewData());
    }

    public function testDeletingWithoutAnActorRecordsNobody(): void
    {
        $document = $this->createPendingDocument();
        $documentId = $document->getId();

        $this->service->delete($document);

        $logs = $this->em->getRepository(AuditLog::class)->findBy(['entityType' => 'Document', 'entityId' => (string) $documentId, 'action' => 'document_delete']);
        $this->assertCount(1, $logs);
        $this->assertNull($logs[0]->getUser());
    }

    public function testDeletingAPendingUploadKeepsADirectoryThatStillHoldsOtherFiles(): void
    {
        $document = $this->createPendingDocument();
        $path = $this->uploadsDir . '/' . $document->getStoredFilename();
        $sibling = dirname($path) . '/sibling.pdf';
        file_put_contents($sibling, '%PDF-1.4 sibling');

        $this->service->delete($document, $this->user);

        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($sibling);
        $this->assertDirectoryExists(dirname($path));
    }

    public function testDeletingACaseDocumentKeepsTheCaseDirectory(): void
    {
        $case = $this->createCase();
        $document = $this->service->upload($case, $this->createTempUploadedFile(), DocumentType::DOVADA, $this->user);
        $path = $this->uploadsDir . '/' . $document->getStoredFilename();
        $this->assertFileExists($path);
        $this->assertStringNotContainsString('/_pending/', $document->getStoredFilename());

        $this->service->delete($document, $this->user);

        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryExists(dirname($path), 'only a pending upload directory is removed when emptied');
    }

    public function testAFileThatCannotBeUnlinkedIsLoggedWhileTheRowStillGoes(): void
    {
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $service = new DocumentUploadService(
            $this->em,
            static::getContainer()->get(AuditLogService::class),
            static::getContainer()->get(FileContentHasher::class),
            $this->uploadsDir,
            $logger,
        );
        // The stored path names a directory, which unlink refuses: the row is
        // gone first, the failure is logged, and nothing else on disk is touched.
        $document = $this->createPendingDocument();
        $documentId = $document->getId();
        $path = $this->uploadsDir . '/' . $document->getStoredFilename();
        unlink($path);
        mkdir($path);
        $this->pendingDirs[] = $path;

        $service->delete($document, $this->user);

        $this->assertNull($this->em->getRepository(Document::class)->find($documentId));
        $this->assertDirectoryExists($path);
        $this->assertDirectoryExists(dirname($path));
        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertSame('document.delete.unlink_failed', $records[0]['message']);
        $this->assertSame($document->getStoredFilename(), $records[0]['context']['path']);
        $this->assertCount(1, $this->em->getRepository(AuditLog::class)->findBy(['entityType' => 'Document', 'entityId' => (string) $documentId, 'action' => 'document_delete']));
    }

    /** A document uploaded before a case exists: its file sits in its own directory under the user's pending area. */
    private function createPendingDocument(): Document
    {
        $relative = 'cases/_pending/' . $this->user->getId() . '/' . uniqid('svc-', true) . '/pending.pdf';
        $absolute = $this->uploadsDir . '/' . $relative;
        mkdir(dirname($absolute), 0o777, true);
        $this->pendingDirs[] = dirname($absolute);
        file_put_contents($absolute, '%PDF-1.4 pending');

        $document = new Document();
        $document->setUploadedBy($this->user);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('pending.pdf');
        $document->setStoredFilename($relative);
        $document->setMimeType('application/pdf');
        $document->setFileSize(16);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        // Taken before the rows go: the uploads directory is shared with the dev
        // environment, so only this test's own case folders may be emptied.
        $caseIds = $conn->fetchFirstColumn(
            'SELECT lc.id FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?',
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE al FROM audit_log al WHERE al.entity_type = 'Document' AND al.entity_id IN (SELECT d.id FROM document d JOIN legal_case lc ON d.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?)",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement(
            "DELETE d FROM document d JOIN legal_case lc ON d.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$this->user->getId()]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$this->user->getId()]);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);

        foreach (array_reverse($this->pendingDirs) as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                is_dir($file) ? @rmdir($file) : @unlink($file);
            }
            @rmdir($dir);
        }
        @rmdir($this->uploadsDir . '/cases/_pending/' . $this->user->getId());

        foreach ($caseIds as $caseId) {
            $dir = $this->uploadsDir . '/cases/' . $caseId;
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }
}
