<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\Document\FileContentHasher;
use PHPUnit\Framework\TestCase;

final class FileContentHasherTest extends TestCase
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

    public function testHashContentsMatchesSha256(): void
    {
        self::assertSame(hash('sha256', 'invoice bytes'), $this->hasher->hashContents('invoice bytes'));
    }

    public function testHashIsStableAcrossCalls(): void
    {
        $path = $this->writeTempFile('stable content');

        self::assertSame($this->hasher->hashFile($path), $this->hasher->hashFile($path));
    }

    public function testHashLengthFitsTheContentHashColumn(): void
    {
        $path = $this->writeTempFile('anything');

        self::assertSame(64, \strlen((string) $this->hasher->hashFile($path)));
    }

    public function testIdenticalContentInDifferentFilesGivesTheSameHash(): void
    {
        $first = $this->writeTempFile('same bytes');
        $second = $this->writeTempFile('same bytes');

        self::assertSame($this->hasher->hashFile($first), $this->hasher->hashFile($second));
    }

    public function testDifferentContentGivesDifferentHash(): void
    {
        $first = $this->writeTempFile('bytes A');
        $second = $this->writeTempFile('bytes B');

        self::assertNotSame($this->hasher->hashFile($first), $this->hasher->hashFile($second));
    }

    public function testFileHashEqualsStringHashOfTheSameBytes(): void
    {
        $path = $this->writeTempFile('binary-ish \x00 payload');

        self::assertSame($this->hasher->hashContents('binary-ish \x00 payload'), $this->hasher->hashFile($path));
    }

    public function testMissingFileYieldsNull(): void
    {
        self::assertNull($this->hasher->hashFile(sys_get_temp_dir() . '/does-not-exist-' . uniqid()));
    }

    public function testDirectoryYieldsNull(): void
    {
        self::assertNull($this->hasher->hashFile(sys_get_temp_dir()));
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hasher-test-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
