<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Extraction\DeduplicationResult;
use App\DTO\Extraction\WizardPrefillResult;
use App\DTO\Wizard\ClaimItemRow;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\PersonType;
use App\Repository\DocumentRepository;
use App\Service\Case\DocumentReferenceNormalizer;
use App\Service\Party\RomanianBankCode;

/**
 * Reads the source documents of a wizard run: which ones the user owns, how
 * far their extraction got, and what the step 0 card summarises from them.
 */
final class WizardDocumentReader
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {}

    /**
     * @param list<int> $documentIds
     * @return list<Document>
     */
    public function loadOwnedDocuments(array $documentIds, User $user): array
    {
        if ($documentIds === []) {
            return [];
        }
        $found = $this->documents->findBy(['id' => $documentIds]);
        $owned = array_values(array_filter(
            $found,
            static fn (Document $d) => $d->getUploadedBy()->getId() === $user->getId(),
        ));

        $byId = [];
        foreach ($owned as $d) {
            $byId[$d->getId()] = $d;
        }
        $ordered = [];
        foreach ($documentIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param list<Document> $documents
     */
    public function allTerminal(array $documents): bool
    {
        if ($documents === []) {
            return false;
        }
        foreach ($documents as $document) {
            if (!$document->getExtractionStatus()->isTerminal()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $documentIds
     * @return array<int, string>
     */
    public function conflictDocumentNames(array $documentIds): array
    {
        return array_map(
            static fn (array $descriptor): string => (string) $descriptor['filename'],
            $this->conflictDocumentDescriptors($documentIds),
        );
    }

    /**
     * How each source file can be named in the record: what the lawyer called it
     * and what it actually contains.
     *
     * The hash is what ties a decision to the file that ended up in the filing
     * bundle. An id points at a row that can be deleted, and a name can be
     * reused; neither answers, two years on, which document was read.
     *
     * @param list<int> $documentIds
     * @return array<int, array{filename: ?string, contentHash: ?string}>
     */
    public function conflictDocumentDescriptors(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $descriptors = [];
        foreach ($this->documents->findBy(['id' => $documentIds]) as $document) {
            $id = $document->getId();
            if ($id !== null) {
                $descriptors[$id] = [
                    'filename' => $document->getOriginalFilename(),
                    'contentHash' => $document->getContentHash(),
                ];
            }
        }

        return $descriptors;
    }

    /**
     * Every creditor account the documents print, once each with its bank, so
     * the lawyer picks the account and the bank together.
     *
     * @param list<int> $documentIds
     * @return list<array{iban: string, bankName: ?string}>
     */
    public function creditorBankAccounts(array $documentIds, User $user): array
    {
        $accounts = [];
        foreach ($this->loadOwnedDocuments($documentIds, $user) as $document) {
            $creditor = $document->getExtractedData()['creditor'] ?? null;
            if (!is_array($creditor)) {
                continue;
            }
            $listed = is_array($creditor['bankAccounts'] ?? null) ? $creditor['bankAccounts'] : [];
            $listed[] = ['iban' => $creditor['iban'] ?? null, 'bankName' => $creditor['bankName'] ?? null];
            foreach ($listed as $entry) {
                $iban = is_array($entry) && is_string($entry['iban'] ?? null) ? RomanianBankCode::wellFormed($entry['iban']) : null;
                if ($iban === null || isset($accounts[$iban])) {
                    continue;
                }
                // The document's own wording ("Trezoreria Ilfov") is kept when it
                // names the account's bank; otherwise the code decides.
                $stated = is_string($entry['bankName'] ?? null) && $entry['bankName'] !== '' ? $entry['bankName'] : null;
                $bank = $stated !== null && RomanianBankCode::nameMatches($stated, $iban) ? $stated : (RomanianBankCode::bankName($iban) ?? $stated);
                $accounts[$iban] = ['iban' => $iban, 'bankName' => $bank];
            }
        }

        return array_values($accounts);
    }

    /**
     * The name of a debtor the documents describe as a consumer: a natural
     * person with no tax registration. The product covers claims between
     * professionals only, so such a file is turned away at step 0 rather than
     * walked through three steps and stopped at the debtor. A sole trader (PFA,
     * II) carries a CUI and is not caught here.
     */
    public function consumerDebtorName(WizardPrefillResult $preview): ?string
    {
        foreach ($preview->debtors->debtors as $debtor) {
            if ($debtor->personType === PersonType::PF && ($debtor->cui === null || trim($debtor->cui) === '')) {
                return $debtor->name ?? '';
            }
        }

        return null;
    }

    /**
     * What the uploaded invoices add up to, for the step 0 card. The aggregated
     * claim carries one invoice worth of figures by construction, so showing it
     * alone next to several invoices reads as if the rest were missed.
     *
     * @return array{count: int, principal: float, currency: string, earliestDueDate: ?\DateTimeImmutable}|null
     */
    public function positionsPreview(DeduplicationResult $collected): ?array
    {
        // The card adds up what the claim table counts, not every document's sum.
        $rows = $collected->primaryRows();
        $positions = array_unique(array_map(
            static fn (ClaimItemRow $r): string => DocumentReferenceNormalizer::normalize($r->documentNumber) ?? $r->dedupKey,
            $collected->rows,
        ));
        if ($rows === [] || (count($rows) === 1 && count($positions) === 1)) {
            // One invoice adds nothing to the claim card, unless another was
            // excluded: the aggregated claim may then be the excluded one.
            return null;
        }

        $principal = 0.0;
        $earliest = null;
        foreach ($rows as $row) {
            $principal += $row->signedAmountRon() ?? 0.0;
            if ($row->dueDate !== null && ($earliest === null || $row->dueDate < $earliest)) {
                $earliest = $row->dueDate;
            }
        }

        return [
            'count' => count($rows),
            'principal' => round($principal, 2),
            'currency' => 'RON',
            'earliestDueDate' => $earliest,
        ];
    }
}
