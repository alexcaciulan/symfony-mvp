<?php

namespace App\Tests\Service\Document;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Service\Document\DocumentUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * B2 audit fix — server-side MIME sniffing on upload. The legacy
 * {@see \App\Tests\Service\DocumentUploadServiceTest} predates the cascade
 * extraction work and uses the string-based `setStatus('paid')` API that
 * the entity rejected in Pas 1.x; those tests sit in the baseline 41/4
 * failures and are fixed under a separate cleanup. This class targets only
 * the new MIME-validation behaviour and uses the enum-based API so it runs
 * green against the current entity contract.
 */
class DocumentUploadMimeValidationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentUploadService $service;
    private User $user;
    private string $testPrefix;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(DocumentUploadService::class);
        $this->testPrefix = 'mimevalidation-' . uniqid();

        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
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
        // Manual cleanup respecting FK order — Document → LegalCase → User.
        $this->em->createQuery('DELETE FROM App\Entity\Document d WHERE d.legalCase IN (SELECT c FROM App\Entity\LegalCase c WHERE c.user = :u)')
            ->setParameter('u', $this->user)
            ->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LegalCase c WHERE c.user = :u')
            ->setParameter('u', $this->user)
            ->execute();
        $this->em->remove($this->user);
        $this->em->flush();
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

    /**
     * Writes content to a tempfile, registers it for cleanup, returns the path.
     */
    private function tempFile(string $content, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docmime_') . '.' . $extension;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * 67-byte minimal PNG (1×1 transparent pixel) — finfo recognises it as image/png.
     */
    private function tempPng(): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABXvMqOgAAAABJRU5ErkJggg==');
        $this->assertNotFalse($bytes);

        return $this->tempFile($bytes, 'png');
    }

    /**
     * Minimal PDF header — finfo recognises it as application/pdf.
     */
    private function tempPdf(): string
    {
        $bytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\nxref\n0 1\n0000000000 65535 f \ntrailer<</Size 1/Root 1 0 R>>\nstartxref\n0\n%%EOF";

        return $this->tempFile($bytes, 'pdf');
    }

    // ---------- happy paths: real PNG and real PDF accepted ----------

    public function testUploadAcceptsRealPng(): void
    {
        $case = $this->createCase();
        $upload = new UploadedFile($this->tempPng(), 'photo.png', 'image/png', null, true);

        $document = $this->service->upload($case, $upload, DocumentType::FACTURA, $this->user);

        $this->assertSame('image/png', $document->getMimeType());
    }

    public function testUploadAcceptsRealPdf(): void
    {
        $case = $this->createCase();
        $upload = new UploadedFile($this->tempPdf(), 'invoice.pdf', 'application/pdf', null, true);

        $document = $this->service->upload($case, $upload, DocumentType::FACTURA, $this->user);

        $this->assertSame('application/pdf', $document->getMimeType());
    }

    public function testUploadAcceptsRealGif(): void
    {
        $case = $this->createCase();
        // Minimal 1×1 transparent GIF87a — finfo reports image/gif.
        $gifBytes = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
        $this->assertNotFalse($gifBytes);
        $upload = new UploadedFile($this->tempFile($gifBytes, 'gif'), 'photo.gif', 'image/gif', null, true);

        $document = $this->service->upload($case, $upload, DocumentType::FACTURA, $this->user);

        $this->assertSame('image/gif', $document->getMimeType());
    }

    public function testUploadAcceptsRealWebp(): void
    {
        $case = $this->createCase();
        // Minimal 1×1 lossless WebP — RIFF/WEBP container, ~26 bytes.
        $webpBytes = base64_decode('UklGRiYAAABXRUJQVlA4IBoAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v3AgAA=');
        $this->assertNotFalse($webpBytes);
        $upload = new UploadedFile($this->tempFile($webpBytes, 'webp'), 'photo.webp', 'image/webp', null, true);

        $document = $this->service->upload($case, $upload, DocumentType::FACTURA, $this->user);

        $this->assertSame('image/webp', $document->getMimeType());
    }

    // ---------- attack: client-supplied MIME doesn't override sniffed value ----------

    public function testUploadRejectsExeDisguisedAsPng(): void
    {
        // Real attacker scenario: shell script / binary uploaded with a
        // forged Content-Type: image/png header. UploadedFile reflects the
        // header faithfully but finfo sniffs `text/plain` (or
        // application/x-executable for binaries) from the actual content.
        $shell = $this->tempFile("#!/bin/sh\nrm -rf /\n", 'png');
        $case = $this->createCase();
        $upload = new UploadedFile($shell, 'malicious.png', 'image/png', null, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported MIME type/i');

        $this->service->upload($case, $upload, DocumentType::FACTURA, $this->user);
    }

    public function testUploadRejectsTextFileEvenWithImageMimeHeader(): void
    {
        $textPath = $this->tempFile("just some plain text", 'png');
        $case = $this->createCase();
        $upload = new UploadedFile($textPath, 'fake.png', 'image/png', null, true);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->upload($case, $upload, DocumentType::FACTURA, $this->user);
    }

    public function testUploadStoresSniffedMimeTypeNotClientHeader(): void
    {
        // Even a benign mismatch (legitimate PNG uploaded with the wrong
        // Content-Type by a buggy client) must result in the sniffed value
        // being persisted, so the extraction strategies dispatch correctly.
        $case = $this->createCase();
        $upload = new UploadedFile($this->tempPng(), 'photo.bin', 'application/octet-stream', null, true);

        $document = $this->service->upload($case, $upload, DocumentType::FACTURA, $this->user);

        $this->assertSame('image/png', $document->getMimeType(), 'Persisted MIME must come from server-side sniffing');
    }

    public function testUploadCleansUpOrphanFileOnRejection(): void
    {
        // Attacker burning disk by repeatedly POSTing junk would leave files on
        // disk if the rejection path didn't unlink. Verify the on-disk artifact
        // is gone after the exception.
        $junk = $this->tempFile("not an image, not a PDF, just bytes", 'pdf');
        $case = $this->createCase();
        $upload = new UploadedFile($junk, 'junk.pdf', 'application/pdf', null, true);

        try {
            $this->service->upload($case, $upload, DocumentType::FACTURA, $this->user);
            self::fail('Upload of MIME-mismatched file should have thrown');
        } catch (\InvalidArgumentException $e) {
            // The on-disk path inside uploadsDir/cases/{caseId}/ must be gone.
            $uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
            $caseDir = $uploadsDir . '/cases/' . $case->getId();
            // Either the dir doesn't exist (unlikely, $file->move created it) or it's empty.
            $entries = is_dir($caseDir) ? array_diff(scandir($caseDir) ?: [], ['.', '..']) : [];
            $this->assertSame([], array_values($entries), 'Orphan file must be unlinked on rejection');
        }
    }
}
