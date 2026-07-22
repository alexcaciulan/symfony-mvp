<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Document;
use App\Service\Document\FileContentHasher;
use App\Service\Document\UploadDeduplicator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Adversarial partitioning cases: the ones a hurried implementation gets wrong
 * because it compares filenames, sizes or client MIME types instead of bytes.
 */
final class UploadDeduplicatorAdversarialTest extends TestCase
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

    public function testSameContentUnderACompletelyDifferentNameIsADuplicate(): void
    {
        // Browsers rename re-downloaded files ("factura (2).pdf"), and users
        // rename them by hand. Only the bytes may decide.
        $existing = $this->makeDocument($this->hasher->hashContents('invoice bytes'));
        $renamed = $this->makeUpload('invoice bytes', 'factura-copie-finala (2).PDF');

        $result = $this->deduplicator->partition([$renamed], [$existing]);

        self::assertSame([], $result['files']);
        self::assertSame(['factura-copie-finala (2).PDF'], $result['duplicates']);
    }

    public function testDifferentContentUnderTheSameNameIsNotADuplicate(): void
    {
        // Two different invoices both exported as "factura.pdf" is the single
        // most common real-world batch. Name-based dedup would eat one of them.
        $existing = $this->makeDocument($this->hasher->hashContents('invoice 001'));
        $other = $this->makeUpload('invoice 002', 'factura.pdf');

        $result = $this->deduplicator->partition([$other], [$existing]);

        self::assertSame([$other], $result['files']);
        self::assertSame([], $result['duplicates']);
    }

    public function testTwoDifferentFilesWithTheSameNameInOneBatchBothSurvive(): void
    {
        $first = $this->makeUpload('contract A', 'contract.pdf');
        $second = $this->makeUpload('contract B', 'contract.pdf');

        $result = $this->deduplicator->partition([$first, $second], []);

        self::assertSame([$first, $second], $result['files']);
        self::assertSame([], $result['duplicates']);
    }

    public function testSameSizeDifferentContentIsNotADuplicate(): void
    {
        // Size-based shortcuts collapse these two; sha256 does not.
        $existing = $this->makeDocument($this->hasher->hashContents('AAAAAAAAAA'));
        $file = $this->makeUpload('BBBBBBBBBB', 'other.pdf');

        $result = $this->deduplicator->partition([$file], [$existing]);

        self::assertSame([$file], $result['files']);
    }

    public function testClientMimeTypeDoesNotInfluenceTheMatch(): void
    {
        // Identical bytes announced as image/png must still collide with the
        // stored fingerprint of the same bytes announced as application/pdf.
        $existing = $this->makeDocument($this->hasher->hashContents('shared payload'));
        $file = $this->makeUpload('shared payload', 'scan.png', 'image/png');

        $result = $this->deduplicator->partition([$file], [$existing]);

        self::assertSame([], $result['files']);
        self::assertSame(['scan.png'], $result['duplicates']);
    }

    public function testMixedBatchKeepsExactlyTheTwoNewFilesAndTheirOrder(): void
    {
        $existing = $this->makeDocument($this->hasher->hashContents('already here'));
        $newA = $this->makeUpload('fresh A', 'a.pdf');
        $dupe = $this->makeUpload('already here', 'dupe.pdf');
        $newB = $this->makeUpload('fresh B', 'b.pdf');

        $result = $this->deduplicator->partition([$newA, $dupe, $newB], [$existing]);

        self::assertSame([$newA, $newB], $result['files'], 'Order of accepted files must be preserved');
        self::assertSame(['dupe.pdf'], $result['duplicates']);
    }

    public function testTenIdenticalFilesInOneBatchCollapseToOne(): void
    {
        $files = [];
        for ($i = 0; $i < 10; ++$i) {
            $files[] = $this->makeUpload('one and the same', sprintf('copy-%d.pdf', $i));
        }

        $result = $this->deduplicator->partition($files, []);

        self::assertCount(1, $result['files']);
        self::assertSame($files[0], $result['files'][0], 'The first copy is the one that survives');
        self::assertCount(9, $result['duplicates']);
    }

    public function testEmptyBatchIsHandled(): void
    {
        $result = $this->deduplicator->partition([], [$this->makeDocument($this->hasher->hashContents('x'))]);

        self::assertSame([], $result['files']);
        self::assertSame([], $result['duplicates']);
    }

    public function testTwoEmptyFilesInOneBatchCollapse(): void
    {
        $first = $this->makeUpload('', 'blank-a.pdf');
        $second = $this->makeUpload('', 'blank-b.pdf');

        $result = $this->deduplicator->partition([$first, $second], []);

        self::assertSame([$first], $result['files'], 'Zero-byte files must not crash the partitioner');
        self::assertSame(['blank-b.pdf'], $result['duplicates']);
    }

    public function testDraftWithNoDocumentsAcceptsEverything(): void
    {
        // Scenario "same file, second case": the new draft carries no documents,
        // so a file already used in a previous case must be accepted again.
        $file = $this->makeUpload('the same contract', 'contract.pdf');

        $result = $this->deduplicator->partition([$file], []);

        self::assertSame([$file], $result['files']);
        self::assertSame([], $result['duplicates']);
    }

    private function makeUpload(string $contents, string $clientName, string $clientMime = 'application/pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dedup-adv-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    private function makeDocument(?string $contentHash): Document
    {
        $document = new Document();
        $document->setContentHash($contentHash);

        return $document;
    }
}
