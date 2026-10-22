<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingEmailPart;

/**
 * The exact bytes of each court email, kept next to the case's package files.
 * Written before the SMTP hand-off, so the record of what was sent never depends
 * on rebuilding the message.
 */
final class FilingEmlStorage
{
    public function __construct(
        private readonly string $uploadsDir,
    ) {}

    /** @return string the path relative to the uploads directory */
    public function write(FilingEmailPart $part, string $bytes): string
    {
        $submission = $part->getSubmission();
        $relative = sprintf(
            'cases/%d/filing/submissions/%s/%d.eml',
            (int) $submission->getLegalCase()->getId(),
            $submission->getToken(),
            $part->getSequence(),
        );
        $absolute = $this->uploadsDir . '/' . $relative;
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the filing email directory.');
        }
        if (@file_put_contents($absolute, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new \RuntimeException('Cannot write the filing email copy.');
        }

        return $relative;
    }

    /** The absolute path of a stored copy whose bytes still match its recorded hash, or null. */
    public function verifiedPath(FilingEmailPart $part): ?string
    {
        if ($part->getEmlPath() === null || $part->getEmlSha256() === null) {
            return null;
        }
        $absolute = $this->uploadsDir . '/' . $part->getEmlPath();
        if (!is_file($absolute) || hash_file('sha256', $absolute) !== $part->getEmlSha256()) {
            return null;
        }

        return $absolute;
    }
}
