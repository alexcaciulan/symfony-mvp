<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Service\Document\DocumentUploadService;
use App\Service\Document\FileContentHasher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service-level contract of D7: what exactly lands in document.content_hash,
 * and what the persisted value is allowed to depend on.
 *
 * The whole dedup feature rests on one invariant: the hash the deduplicator
 * computes on the *temporary* upload must equal the hash DocumentUploadService
 * stamps on the *stored* file after move() + MIME sniffing. If the two ever
 * diverge, dedup silently never fires and nothing else in the suite notices.
 */
final class DocumentContentHashAdversarialTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentUploadService $service;
    private FileContentHasher $hasher;
    private User $user;
    private string $uploadsDir;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(DocumentUploadService::class);
        $this->hasher = new FileContentHasher();
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';

        $this->user = new User();
        $this->user->setEmail('contenthash-' . uniqid() . '@test.com');
        $this->user->setPassword('hashed');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    public function testStoredHashEqualsSha256OfTheOriginalBytes(): void
    {
        $bytes = $this->pdfBytes('payload-1');
        $document = $this->service->upload(null, $this->upload($bytes, 'invoice.pdf'), DocumentType::FACTURA, $this->user);

        self::assertSame(
            hash('sha256', $bytes),
            $document->getContentHash(),
            'The persisted fingerprint must be sha256 over the exact bytes the user sent',
        );
    }

    public function testStoredHashMatchesWhatTheDeduplicatorComputesOnTheTempFile(): void
    {
        // The load-bearing invariant of the whole feature.
        $bytes = $this->pdfBytes('payload-2');
        $tempPath = $this->tempFile($bytes);
        $preMoveHash = $this->hasher->hashFile($tempPath);

        $document = $this->service->upload(
            null,
            new UploadedFile($tempPath, 'invoice.pdf', 'application/pdf', null, true),
            DocumentType::FACTURA,
            $this->user,
        );

        self::assertNotNull($preMoveHash);
        self::assertSame($preMoveHash, $document->getContentHash());
    }

    public function testHashDoesNotDependOnTheSniffedMimeType(): void
    {
        // A PNG and a PDF cannot share bytes, so we assert the weaker but
        // meaningful property: for each type the stored hash is exactly the
        // sha256 of the raw bytes, with no MIME string mixed in.
        $pdfBytes = $this->pdfBytes('mime-check');
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABXvMqOgAAAABJRU5ErkJggg==');
        self::assertNotFalse($pngBytes);

        $pdfDoc = $this->service->upload(null, $this->upload($pdfBytes, 'doc.pdf'), DocumentType::FACTURA, $this->user);
        $pngDoc = $this->service->upload(null, $this->upload($pngBytes, 'scan.png', 'image/png'), DocumentType::FACTURA, $this->user);

        self::assertSame('application/pdf', $pdfDoc->getMimeType());
        self::assertSame('image/png', $pngDoc->getMimeType());
        self::assertSame(hash('sha256', $pdfBytes), $pdfDoc->getContentHash());
        self::assertSame(hash('sha256', $pngBytes), $pngDoc->getContentHash());
    }

    public function testHashDoesNotDependOnTheClientDeclaredMimeTypeOrFilename(): void
    {
        // Same PNG bytes, once announced honestly and once with a forged
        // Content-Type + a .pdf name. Both must fingerprint identically,
        // otherwise a duplicate could be smuggled past dedup by renaming.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABXvMqOgAAAABJRU5ErkJggg==');
        self::assertNotFalse($pngBytes);

        $honest = $this->service->upload(null, $this->upload($pngBytes, 'scan.png', 'image/png'), DocumentType::FACTURA, $this->user);
        $forged = $this->service->upload(null, $this->upload($pngBytes, 'scan.pdf', 'application/pdf'), DocumentType::FACTURA, $this->user);

        self::assertSame($honest->getContentHash(), $forged->getContentHash());
        self::assertSame('image/png', $forged->getMimeType(), 'MIME still comes from sniffing, not from the header');
    }

    public function testSameFileCanBeAttachedToTwoDifferentCasesOfTheSameLawyer(): void
    {
        // Product-critical: one framework contract legitimately backs two
        // payment-order cases against two debtors. A global (per-user)
        // fingerprint guard, or a unique index on (uploaded_by, content_hash),
        // would make the second case impossible to build.
        $caseA = $this->createCase();
        $caseB = $this->createCase();
        $bytes = $this->pdfBytes('shared-contract');

        $docA = $this->service->upload($caseA, $this->upload($bytes, 'contract.pdf'), DocumentType::CONTRACT, $this->user);
        $docB = $this->service->upload($caseB, $this->upload($bytes, 'contract.pdf'), DocumentType::CONTRACT, $this->user);

        self::assertNotSame($docA->getId(), $docB->getId());
        self::assertSame($docA->getContentHash(), $docB->getContentHash());
        self::assertSame($caseA->getId(), $docA->getLegalCase()->getId());
        self::assertSame($caseB->getId(), $docB->getLegalCase()->getId());
        self::assertNotSame($docA->getStoredFilename(), $docB->getStoredFilename(), 'Each case keeps its own copy on disk');
    }

    public function testTwoUsersUploadingTheSameBytesEachKeepTheirOwnDocument(): void
    {
        $other = new User();
        $other->setEmail('contenthash-other-' . uniqid() . '@test.com');
        $other->setPassword('hashed');
        $other->setIsVerified(true);
        $this->em->persist($other);
        $this->em->flush();

        $bytes = $this->pdfBytes('cross-tenant');

        try {
            $mine = $this->service->upload(null, $this->upload($bytes, 'invoice.pdf'), DocumentType::FACTURA, $this->user);
            $theirs = $this->service->upload(null, $this->upload($bytes, 'invoice.pdf'), DocumentType::FACTURA, $other);

            self::assertNotSame($mine->getId(), $theirs->getId());
            self::assertSame($mine->getContentHash(), $theirs->getContentHash());
            self::assertSame($this->user->getId(), $mine->getUploadedBy()->getId());
            self::assertSame($other->getId(), $theirs->getUploadedBy()->getId());
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $other->getId()]);
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $other->getId()]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $other->getId()]);
        }
    }

    public function testDeleteRemovesTheFingerprintSoTheFileCanBeUploadedAgain(): void
    {
        $bytes = $this->pdfBytes('delete-then-reupload');
        $first = $this->service->upload(null, $this->upload($bytes, 'invoice.pdf'), DocumentType::FACTURA, $this->user);
        $firstId = $first->getId();
        $firstPath = $this->uploadsDir . '/' . $first->getStoredFilename();
        self::assertFileExists($firstPath);

        $this->service->delete($first);

        self::assertNull($this->em->find(Document::class, $firstId), 'delete() is a hard delete, not a soft flag');
        self::assertFileDoesNotExist($firstPath);

        $second = $this->service->upload(null, $this->upload($bytes, 'invoice.pdf'), DocumentType::FACTURA, $this->user);

        self::assertNotSame($firstId, $second->getId());
        self::assertSame(hash('sha256', $bytes), $second->getContentHash());
    }

    public function testRejectedUploadLeavesNoRowAndNoFingerprintBehind(): void
    {
        // A file that fails the MIME whitelist must not register a fingerprint
        // that would later mask a legitimate upload of different content.
        $before = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM document WHERE uploaded_by_id = :id',
            ['id' => $this->user->getId()],
        );

        try {
            $this->service->upload(null, $this->upload("#!/bin/sh\necho hi\n", 'evil.pdf'), DocumentType::FACTURA, $this->user);
            self::fail('Expected the MIME whitelist to reject a shell script');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $after = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM document WHERE uploaded_by_id = :id',
            ['id' => $this->user->getId()],
        );
        self::assertSame($before, $after);
    }

    private function createCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus(CaseStatus::AMIABIL);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function pdfBytes(string $marker): string
    {
        return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Size 1/Root 1 0 R>>\n% "
            . $marker . "\n%%EOF\n";
    }

    private function upload(string $bytes, string $clientName, string $clientMime = 'application/pdf'): UploadedFile
    {
        return new UploadedFile($this->tempFile($bytes), $clientName, $clientMime, null, true);
    }

    private function tempFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contenthash-');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }
}
