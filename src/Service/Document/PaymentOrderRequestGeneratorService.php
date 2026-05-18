<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\LegalCase;
use App\Enum\DocumentType;

/**
 * Generează cererea de ordonanță de plată (CPC art. 1014-1024).
 *
 * Documentul cu care creditorul se adresează instanței competente după ce
 * somația (CPC art. 1015) a fost comunicată debitorului fără rezultat. Conține
 * elementele obligatorii prevăzute la CPC art. 1016: instanța competentă,
 * datele părților, expunerea faptelor, sumele cerute (principal + dobândă +
 * cheltuieli judiciare), temei juridic și anexe (referință la opis).
 */
final class PaymentOrderRequestGeneratorService extends AbstractPdfGenerator
{
    protected function templatePath(): string
    {
        return 'pdf/payment_order_request.html.twig';
    }

    protected function documentType(): DocumentType
    {
        return DocumentType::CERERE_OP;
    }

    protected function originalFilenameFor(LegalCase $case): string
    {
        return 'Cerere_OP_' . $case->getCaseNumber() . '.pdf';
    }

    protected function storedFilenameStem(LegalCase $case): string
    {
        return 'cerere_op_' . $case->getId();
    }
}
