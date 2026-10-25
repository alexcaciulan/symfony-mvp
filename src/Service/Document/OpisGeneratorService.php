<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\ClaimItem;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\DocumentType;

/**
 * Generează opisul documentelor (lista numerotată cu toate documentele
 * atașate dosarului). Anexă obligatorie a cererii de ordonanță de plată
 * (CPC art. 1.017). Sortare: SOMATIE prima, apoi
 * DOVADA_COMUNICARE, apoi restul cronologic după createdAt.
 */
final class OpisGeneratorService extends AbstractPdfGenerator
{
    protected function templatePath(): string
    {
        return 'pdf/document_index.html.twig';
    }

    protected function documentType(): DocumentType
    {
        return DocumentType::OPIS;
    }

    protected function originalFilenameFor(LegalCase $case): string
    {
        return 'Opis_' . $case->getCaseNumber() . '.pdf';
    }

    protected function storedFilenameStem(LegalCase $case): string
    {
        return 'opis_' . $case->getId();
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateContext(LegalCase $case): array
    {
        return [
            ...parent::templateContext($case),
            'documents' => $this->orderedDocuments($case),
            'claim_items_by_document' => $this->claimItemsByDocument($case),
        ];
    }

    /**
     * The annexes in the order the index lists them: summons, proof of service,
     * stamp-duty proof, power of attorney, contract, invoices, then the rest by
     * upload time. The index and the petition are left out: the index lists the
     * annexes of the petition (CPC art. 1.017), and the petition is not one.
     * Shared with the filing package so its bundle and its ranges follow the same order.
     *
     * @return list<Document>
     */
    public function orderedDocuments(LegalCase $case): array
    {
        $documents = $case->getDocuments()->toArray();
        $documents = array_filter($documents, static fn (Document $d): bool =>
            $d->getDocumentType() !== DocumentType::OPIS
            && $d->getDocumentType() !== DocumentType::CERERE_OP
        );

        usort($documents, static function (Document $a, Document $b): int {
            $order = [
                DocumentType::SOMATIE->value => 1,
                DocumentType::DOVADA_COMUNICARE->value => 2,
                DocumentType::DOVADA_TAXA_TIMBRU->value => 3,
                DocumentType::IMPUTERNICIRE_AVOCATIALA->value => 4,
                DocumentType::CONTRACT->value => 5,
                DocumentType::FACTURA->value => 6,
            ];
            $aRank = $order[$a->getDocumentType()->value] ?? 10;
            $bRank = $order[$b->getDocumentType()->value] ?? 10;

            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        return array_values($documents);
    }

    /**
     * Which claim positions each annex proves, so the court can tie a filed
     * invoice to the sum asked for it. Excluded and unconfirmed positions are
     * not in the claim, so they are not in the index either.
     *
     * @return array<int, list<ClaimItem>>
     */
    public function claimItemsByDocument(LegalCase $case): array
    {
        $byDocument = [];
        foreach ($case->getCountingClaimItems() as $item) {
            $documentId = $item->getSourceDocument()?->getId();
            if ($documentId === null) {
                continue;
            }
            $byDocument[$documentId][] = $item;
        }

        return $byDocument;
    }
}
