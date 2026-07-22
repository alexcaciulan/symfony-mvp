<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\Document\FileContentHasher;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial edge cases for the upload fingerprint.
 *
 * The dedup feature only works if the hash is a pure function of the bytes:
 * it must not depend on the filename, the extension, the client-declared MIME
 * type or the size of the payload, and it must survive degenerate inputs
 * (zero bytes, one byte, embedded NUL) without throwing.
 */
final class FileContentHasherEdgeCaseTest extends TestCase
{
    private FileContentHasher $hasher;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->hasher = new FileContentHasher();
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

    public function testEmptyFileHashesToTheSha256OfTheEmptyString(): void
    {
        $path = $this->writeTempFile('');

        $hash = $this->hasher->hashFile($path);

        self::assertNotNull($hash, 'A zero-byte file is readable content, not an error');
        self::assertSame(hash('sha256', ''), $hash);
        self::assertSame(64, \strlen($hash));
    }

    public function testSingleByteFileHashes(): void
    {
        $path = $this->writeTempFile('A');

        self::assertSame(hash('sha256', 'A'), $this->hasher->hashFile($path));
    }

    public function testTwoEmptyFilesShareTheSameFingerprint(): void
    {
        // Two zero-byte uploads really are identical bytes, so the deduplicator
        // is right to collapse them. Pinned so a future "treat empty as unknown"
        // shortcut cannot silently change the semantics.
        $first = $this->writeTempFile('');
        $second = $this->writeTempFile('');

        self::assertSame($this->hasher->hashFile($first), $this->hasher->hashFile($second));
    }

    public function testContentWithEmbeddedNulBytesIsHashedInFull(): void
    {
        // A truncating implementation (fgets / strlen on a C string) would give
        // the same hash for both payloads because they share the prefix up to
        // the NUL byte.
        $first = $this->writeTempFile("PDF\x00head\x00tail-A");
        $second = $this->writeTempFile("PDF\x00head\x00tail-B");

        self::assertNotSame($this->hasher->hashFile($first), $this->hasher->hashFile($second));
        self::assertSame(hash('sha256', "PDF\x00head\x00tail-A"), $this->hasher->hashFile($first));
    }

    public function testHashIgnoresFilenameAndExtension(): void
    {
        $pdfNamed = $this->writeTempFile('identical payload', '.pdf');
        $pngNamed = $this->writeTempFile('identical payload', '.png');

        self::assertSame($this->hasher->hashFile($pdfNamed), $this->hasher->hashFile($pngNamed));
    }

    public function testTrailingByteChangesTheFingerprint(): void
    {
        // Guards against a "hash the first N bytes" optimisation: two documents
        // that differ only in the last byte (e.g. a regenerated invoice) must
        // not be collapsed into one.
        $base = str_repeat('%PDF-1.4 payload ', 4096);
        $first = $this->writeTempFile($base . 'X');
        $second = $this->writeTempFile($base . 'Y');

        self::assertNotSame($this->hasher->hashFile($first), $this->hasher->hashFile($second));
    }

    public function testUnreadableFileYieldsNullInsteadOfThrowing(): void
    {
        $path = $this->writeTempFile('secret bytes');
        chmod($path, 0o000);

        try {
            if (is_readable($path)) {
                // Running as root (common in the Docker php container) makes
                // every file readable, so the negative case cannot be built.
                self::markTestSkipped('Process can read mode-000 files; cannot simulate an unreadable upload.');
            }

            self::assertNull($this->hasher->hashFile($path));
        } finally {
            chmod($path, 0o600);
        }
    }

    private function writeTempFile(string $contents, string $suffix = ''): string
    {
        $base = tempnam(sys_get_temp_dir(), 'hasher-edge-');
        $this->tempFiles[] = $base;
        $path = $base . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
