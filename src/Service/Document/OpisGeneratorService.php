<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\DocumentType;

/**
 * Generează opisul documentelor (lista numerotată cu toate documentele
 * atașate dosarului). Anexă obligatorie a cererii de ordonanță de plată
 * (CPC art. 1016 alin. (1) lit. f). Sortare: SOMATIE prima, apoi
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
     * Listă sortată SOMATIE → DOVADA_COMUNICARE → restul. Opisul + cererea OP
     * sunt excluse: opisul listează ANEXELE cererii (CPC art. 1016 alin. 1
     * lit. f), iar cererea însăși NU este o anexă a propriei cereri.
     *
     * @return array<string, mixed>
     */
    protected function templateContext(LegalCase $case): array
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
                DocumentType::CONTRACT->value => 3,
                DocumentType::FACTURA->value => 4,
            ];
            $aRank = $order[$a->getDocumentType()->value] ?? 10;
            $bRank = $order[$b->getDocumentType()->value] ?? 10;

            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        return [
            ...parent::templateContext($case),
            'documents' => array_values($documents),
        ];
    }
}
