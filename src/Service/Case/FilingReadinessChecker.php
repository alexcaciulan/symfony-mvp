<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Case\FilingIssue;
use App\DTO\Case\FilingReadiness;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\DocumentType;
use App\Enum\FilingIssueCode;
use App\Service\Document\PowerOfAttorneyService;

/**
 * The pieces the filing package must carry, checked in one place so the petition
 * generation, the package download and any later sending route refuse the same
 * cases for the same reasons.
 *
 * These are product rules, not admissibility conditions: a missing proof of
 * representation is a curable defect the court gives a term for. The proof that
 * the summons was communicated, which the law does sanction, is checked by its
 * callers on its own.
 */
final class FilingReadinessChecker
{
    /**
     * Whether a case without a contract is refused. Reflects an open question to the
     * consulting lawyer (accepted invoices may prove the claim on their own), not a
     * legal requirement; true moves the missing contract from warnings to blockers.
     */
    private const CONTRACT_BLOCKS_FILING = false;

    /**
     * Whether an uploaded contract proves the claim once the case claims any counting
     * position, even one not linked to it. Art. 1014 CPC admits a claim stated by a
     * contract (a fixed monthly price never invoiced), so refusing such a case would be
     * stricter than the law; false narrows it to contracts a position names as source.
     */
    private const CONTRACT_PROVES_CLAIM = true;

    public function __construct(
        private readonly PowerOfAttorneyService $powerOfAttorneyService,
    ) {}

    public function check(LegalCase $case): FilingReadiness
    {
        $blockers = [];
        $warnings = [];

        $documents = $case->getDocuments()->toArray();
        $powersOfAttorney = array_values(array_filter(
            $documents,
            static fn (Document $d): bool => $d->getDocumentType() === DocumentType::IMPUTERNICIRE_AVOCATIALA,
        ));
        usort($powersOfAttorney, static fn (Document $a, Document $b): int => $b->getCreatedAt() <=> $a->getCreatedAt() ?: ($b->getId() ?? 0) <=> ($a->getId() ?? 0));

        if ($powersOfAttorney === []) {
            $blockers[] = new FilingIssue(FilingIssueCode::POWER_OF_ATTORNEY_MISSING);
        } else {
            $dated = array_filter($powersOfAttorney, static fn (Document $d): bool => $d->hasPowerOfAttorneyDate());
            $undated = array_values(array_filter($powersOfAttorney, static fn (Document $d): bool => !$d->hasPowerOfAttorneyDate()));
            if ($dated === []) {
                // The index prints the date of the act, so one has to be on file.
                $blockers[] = new FilingIssue(FilingIssueCode::POWER_OF_ATTORNEY_DATE_MISSING, documentId: $undated[0]->getId());
            } else {
                // A second, undated copy must not block a case that already has a dated one.
                foreach ($undated as $document) {
                    $warnings[] = new FilingIssue(FilingIssueCode::POWER_OF_ATTORNEY_EXTRA_DATE_MISSING, documentId: $document->getId());
                }
            }

            foreach ($dated as $document) {
                if ($this->powerOfAttorneyService->dateIsAfterSummons($document, $case)) {
                    $warnings[] = new FilingIssue(
                        FilingIssueCode::POWER_OF_ATTORNEY_AFTER_SUMMONS,
                        [
                            '%poa_date%' => $document->getPowerOfAttorneyDate()?->format('d.m.Y') ?? '',
                            '%summons_date%' => $case->getPaymentNoticeDate()?->format('d.m.Y') ?? '',
                        ],
                        $document->getId(),
                    );
                }
            }
        }

        if (!$this->hasClaimDocument($case, $documents)) {
            $blockers[] = new FilingIssue(FilingIssueCode::CLAIM_DOCUMENT_MISSING);
        }

        if (!$case->hasDocumentOfType(DocumentType::CONTRACT)) {
            $issue = new FilingIssue(FilingIssueCode::CONTRACT_MISSING);
            if (self::CONTRACT_BLOCKS_FILING) {
                $blockers[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }

        return new FilingReadiness($blockers, $warnings);
    }

    /**
     * A document stating the debt itself, any document of the case that a counting
     * claim position names as its source or cause, or (see CONTRACT_PROVES_CLAIM) a
     * contract next to at least one counting position. Manual positions with no
     * document on file do not count.
     *
     * @param list<Document> $documents
     */
    private function hasClaimDocument(LegalCase $case, array $documents): bool
    {
        foreach ($documents as $document) {
            if ($document->getEffectiveType()?->isClaimBearing()) {
                return true;
            }
        }

        $countingItems = $case->getCountingClaimItems();
        if ($countingItems === []) {
            return false;
        }

        if (self::CONTRACT_PROVES_CLAIM && $case->hasDocumentOfType(DocumentType::CONTRACT)) {
            return true;
        }

        foreach ($countingItems as $item) {
            foreach ([$item->getSourceDocument(), $item->getCauseDocument()] as $document) {
                if ($document !== null && $document->getLegalCase() === $case) {
                    return true;
                }
            }
        }

        return false;
    }
}
