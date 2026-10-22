<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\InboundMail;

/**
 * The exact bytes of each received message (or uploaded receipt), named by their
 * hash so the same message stored twice is one file. Read back only after the
 * hash is checked again.
 */
final class InboundRawStorage
{
    public function __construct(
        private readonly string $uploadsDir,
    ) {}

    /** @return string the path relative to the uploads directory */
    public function write(string $bytes, string $extension, \DateTimeImmutable $at): string
    {
        $relative = sprintf('inbound/%s/%s/%s.%s', $at->format('Y'), $at->format('m'), hash('sha256', $bytes), $extension);
        $absolute = $this->uploadsDir . '/' . $relative;
        if (is_file($absolute)) {
            return $relative;
        }
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the inbound mail directory.');
        }
        if (@file_put_contents($absolute, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new \RuntimeException('Cannot write the inbound mail copy.');
        }

        return $relative;
    }

    public function verifiedPath(InboundMail $mail): ?string
    {
        $absolute = $this->uploadsDir . '/' . $mail->getRawPath();
        if (!is_file($absolute) || hash_file('sha256', $absolute) !== $mail->getRawSha256()) {
            return null;
        }

        return $absolute;
    }

    public function read(InboundMail $mail): ?string
    {
        $path = $this->verifiedPath($mail);

        return $path !== null ? (string) file_get_contents($path) : null;
    }

    public function delete(string $relativePath): void
    {
        $absolute = $this->uploadsDir . '/' . $relativePath;
        if (str_starts_with($relativePath, 'inbound/') && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public function directory(): string
    {
        return $this->uploadsDir . '/inbound';
    }
}
