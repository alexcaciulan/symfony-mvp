<?php

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Service\Document\DocumentUploadService;
use Doctrine\ORM\EntityManagerInterface;
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

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(DocumentUploadService::class);
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
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

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
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
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);

        // Cleanup uploaded files
        $casesDir = $this->uploadsDir . '/cases';
        if (is_dir($casesDir)) {
            $dirs = glob($casesDir . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $files = glob($dir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }

        parent::tearDown();
    }
}
