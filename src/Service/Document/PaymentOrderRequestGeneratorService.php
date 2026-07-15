<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\LegalCase;
use App\Enum\DocumentType;
use App\Service\Calculation\StampDutyCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Environment;

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
    public function __construct(
        Environment $twig,
        EntityManagerInterface $em,
        Security $security,
        string $uploadsDir,
        private readonly StampDutyCalculator $stampDutyCalculator,
    ) {
        parent::__construct($twig, $em, $security, $uploadsDir);
    }

    /**
     * The stamp duty is owed regardless of whether it was ever written onto the case
     * (older cases predate the field). Passing the statutory amount keeps it out of
     * the petition's costs claim by accident, which would cost the client 200 lei.
     *
     * @return array<string, mixed>
     */
    protected function templateContext(LegalCase $case): array
    {
        return [
            ...parent::templateContext($case),
            'stamp_duty_amount' => (float) ($case->getStampDuty() ?? $this->stampDutyCalculator->calculate()->amount),
        ];
    }

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
