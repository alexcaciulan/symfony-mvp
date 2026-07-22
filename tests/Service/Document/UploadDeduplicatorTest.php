<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Document;
use App\Service\Document\FileContentHasher;
use App\Service\Document\UploadDeduplicator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadDeduplicatorTest extends TestCase
{
    private UploadDeduplicator $deduplicator;
    private FileContentHasher $hasher;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->hasher = new FileContentHasher();
        $this->deduplicator = new UploadDeduplicator($this->hasher);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function testKeepsFilesWithUnknownContent(): void
    {
        $file = $this->makeUpload('brand new', 'invoice.pdf');

        $result = $this->deduplicator->partition([$file], []);

        self::assertSame([$file], $result['files']);
        self::assertSame([], $result['duplicates']);
    }

    public function testSkipsFileAlreadyPresentInTheDraft(): void
    {
        $file = $this->makeUpload('same bytes', 'invoice-copy.pdf');
        $existing = $this->makeDocument($this->hasher->hashContents('same bytes'));

        $result = $this->deduplicator->partition([$file], [$existing]);

        self::assertSame([], $result['files']);
        self::assertSame(['invoice-copy.pdf'], $result['duplicates']);
    }

    public function testSkipsTwinsInsideTheSameBatch(): void
    {
        $first = $this->makeUpload('same bytes', 'invoice.pdf');
        $twin = $this->makeUpload('same bytes', 'invoice (1).pdf');
        $other = $this->makeUpload('other bytes', 'contract.pdf');

        $result = $this->deduplicator->partition([$first, $twin, $other], []);

        self::assertSame([$first, $other], $result['files']);
        self::assertSame(['invoice (1).pdf'], $result['duplicates']);
    }

    public function testOneDuplicateDoesNotDropTheRestOfTheBatch(): void
    {
        $duplicate = $this->makeUpload('known bytes', 'known.pdf');
        $fresh = $this->makeUpload('fresh bytes', 'fresh.pdf');
        $existing = $this->makeDocument($this->hasher->hashContents('known bytes'));

        $result = $this->deduplicator->partition([$duplicate, $fresh], [$existing]);

        self::assertSame([$fresh], $result['files']);
        self::assertSame(['known.pdf'], $result['duplicates']);
    }

    public function testDocumentWithoutHashNeverMatches(): void
    {
        $file = $this->makeUpload('legacy bytes', 'legacy.pdf');
        $existing = $this->makeDocument(null);

        $result = $this->deduplicator->partition([$file], [$existing]);

        self::assertSame([$file], $result['files']);
        self::assertSame([], $result['duplicates']);
    }

    private function makeUpload(string $contents, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dedup-test-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $clientName, 'application/pdf', null, true);
    }

    private function makeDocument(?string $contentHash): Document
    {
        $document = new Document();
        $document->setContentHash($contentHash);

        return $document;
    }
}
