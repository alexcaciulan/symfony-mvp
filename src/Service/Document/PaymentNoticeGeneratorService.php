<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\LegalCase;
use App\Enum\DocumentType;

/**
 * Generează somația de plată (CPC art. 1015 alin. 1).
 *
 * Termenul legal de plată este 15 zile de la primirea somației — citat verbatim
 * în template (NU 30 zile; cele 30 din Legea 72/2013 art. 3 alin. 1 sunt termen
 * supletiv contractual între profesioniști, distinct de somația CPC).
 */
final class PaymentNoticeGeneratorService extends AbstractPdfGenerator
{
    protected function templatePath(): string
    {
        return 'pdf/payment_notice.html.twig';
    }

    protected function documentType(): DocumentType
    {
        return DocumentType::SOMATIE;
    }

    protected function originalFilenameFor(LegalCase $case): string
    {
        return 'Somatie_' . $case->getCaseNumber() . '.pdf';
    }

    protected function storedFilenameStem(LegalCase $case): string
    {
        return 'somatie_' . $case->getId();
    }
}
