<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document;

/**
 * A document the filing package must carry is recorded on the case but its file is
 * gone from storage. Raised instead of quietly leaving it out, because the petition
 * in the same package speaks about those pieces as annexed.
 */
final class MissingDocumentFileException extends \RuntimeException
{
    public function __construct(Document $document)
    {
        parent::__construct(sprintf(
            'File missing from storage for document %d (%s): %s',
            (int) $document->getId(),
            $document->getDocumentType()->value,
            $document->getStoredFilename(),
        ));
    }
}
