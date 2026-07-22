<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Splits an upload batch into files worth storing and files whose bytes are
 * already on file, so the same document never costs a second extraction call.
 *
 * The comparison runs on the temporary upload, before anything is written or
 * persisted: a duplicate then leaves no stored file, no Document row and no
 * extraction message behind, instead of being created and rolled back.
 *
 * Scope is deliberately the documents of the draft at hand, never every
 * document the user ever uploaded. The same invoice can legitimately support
 * two different cases against two different debtors, and a global fingerprint
 * match would block that honest work. Inside a single draft, a second copy of
 * identical bytes carries no new information.
 *
 * The guarantee is best effort: the comparison runs against the rows loaded for
 * the current request, with no lock and no unique constraint behind it, so two
 * concurrent posts of the same file into the same draft can both get through.
 * That is the accepted trade for keeping the same file usable in other cases.
 */
final class UploadDeduplicator
{
    public function __construct(
        private readonly FileContentHasher $hasher,
    ) {}

    /**
     * @param list<UploadedFile> $files           the batch being uploaded
     * @param list<Document>     $alreadyUploaded documents already attached to the draft
     *
     * @return array{files: list<UploadedFile>, duplicates: list<string>} accepted files,
     *                                                                   plus the original names of the skipped ones
     */
    public function partition(array $files, array $alreadyUploaded): array
    {
        $seenHashes = [];
        foreach ($alreadyUploaded as $document) {
            $hash = $document->getContentHash();
            if ($hash !== null) {
                $seenHashes[$hash] = true;
            }
        }

        $accepted = [];
        $duplicates = [];

        foreach ($files as $file) {
            $hash = $this->hasher->hashFile($file->getPathname());
            if ($hash === null) {
                // Unreadable temp file: let the upload path run so the user gets
                // the real validation error instead of a silent skip.
                $accepted[] = $file;
                continue;
            }

            if (isset($seenHashes[$hash])) {
                $duplicates[] = $file->getClientOriginalName();
                continue;
            }

            // Registering the hash right away also catches twin files sent
            // together in one batch, not just collisions with earlier uploads.
            $seenHashes[$hash] = true;
            $accepted[] = $file;
        }

        return ['files' => $accepted, 'duplicates' => $duplicates];
    }
}
