<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\DocumentType;
use App\Service\Document\MissingDocumentFileException;
use App\Service\Document\OpisGeneratorService;

/**
 * Picks the documents of the package. The annexes follow the index order, minus
 * what is not an annex of the petition: the petition and the index themselves,
 * the power of attorney (its own file), the order issued later by the court, and
 * the stamp-duty proof unless the petition says it is annexed.
 */
final class FilingAnnexSelector
{
    private const NEVER_ANNEXED = [
        DocumentType::CERERE_OP,
        DocumentType::OPIS,
        DocumentType::IMPUTERNICIRE_AVOCATIALA,
        DocumentType::ORDONANTA_PLATA,
    ];

    /** Without these the petition speaks of pieces the package does not carry. */
    private const MANDATORY = [
        DocumentType::SOMATIE,
        DocumentType::DOVADA_COMUNICARE,
        DocumentType::DOVADA_TAXA_TIMBRU,
    ];

    public function __construct(
        private readonly OpisGeneratorService $opisGenerator,
        private readonly string $uploadsDir,
    ) {}

    public function select(LegalCase $case): FilingAnnexSelection
    {
        $petition = null;
        foreach ($case->getDocuments() as $document) {
            if ($document->getDocumentType() === DocumentType::CERERE_OP) {
                $petition = $document;
                break;
            }
        }
        if ($petition === null) {
            throw new FilingPackageBlockedException('filing.package.blocked_petition_missing');
        }
        $this->requireFile($petition);

        [$powerOfAttorney, $excluded] = $this->powerOfAttorney($case);
        $this->requireFile($powerOfAttorney);

        $proofAnnexed = $case->stampDutyProofAnnexed();
        $annexes = [];
        $skipped = [];
        foreach ($this->opisGenerator->orderedDocuments($case) as $document) {
            $type = $document->getDocumentType();
            if (in_array($type, self::NEVER_ANNEXED, true)) {
                continue;
            }
            if ($type === DocumentType::DOVADA_TAXA_TIMBRU && !$proofAnnexed) {
                continue;
            }

            if (!is_file($this->path($document))) {
                if (in_array($type, self::MANDATORY, true)) {
                    throw new MissingDocumentFileException($document);
                }
                $skipped[] = $document;
                continue;
            }

            $annexes[] = $document;
        }

        return new FilingAnnexSelection($petition, $powerOfAttorney, $annexes, $proofAnnexed, $skipped, $excluded);
    }

    public function path(Document $document): string
    {
        return $this->uploadsDir . '/' . $document->getStoredFilename();
    }

    /** The power of attorney the package carries, null when no dated one is on the case. */
    public function chosenPowerOfAttorney(LegalCase $case): ?Document
    {
        try {
            return $this->powerOfAttorney($case)[0];
        } catch (FilingPackageBlockedException) {
            return null;
        }
    }

    /**
     * The newest dated power of attorney, the one the readiness check validates.
     *
     * @return array{0: Document, 1: list<Document>}
     */
    private function powerOfAttorney(LegalCase $case): array
    {
        $all = array_values(array_filter(
            $case->getDocuments()->toArray(),
            static fn (Document $d): bool => $d->getDocumentType() === DocumentType::IMPUTERNICIRE_AVOCATIALA,
        ));
        usort($all, static fn (Document $a, Document $b): int => $b->getCreatedAt() <=> $a->getCreatedAt() ?: ($b->getId() ?? 0) <=> ($a->getId() ?? 0));

        $chosen = null;
        $excluded = [];
        foreach ($all as $document) {
            if ($chosen === null && $document->hasPowerOfAttorneyDate()) {
                $chosen = $document;
            } else {
                $excluded[] = $document;
            }
        }

        if ($chosen === null) {
            throw new FilingPackageBlockedException('case_overview.payment_order.flash_error_power_of_attorney_date_missing');
        }

        return [$chosen, $excluded];
    }

    private function requireFile(Document $document): void
    {
        if (!is_file($this->path($document))) {
            throw new MissingDocumentFileException($document);
        }
    }
}
