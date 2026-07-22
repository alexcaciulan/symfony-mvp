<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\Entity\LegalCase;
use App\Enum\DocumentType;
use App\Enum\InterestKind;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\StampDutyCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Environment;

/**
 * Generează cererea de ordonanță de plată (CPC art. 1013-1024).
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
        private readonly ClaimInterestAggregator $accessoryAggregator,
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
        $items = $case->getCountingClaimItems();
        $rate = $case->getContractualPenaltyRate();

        return [
            ...parent::templateContext($case),
            'stamp_duty_amount' => (float) ($case->getStampDuty() ?? $this->stampDutyCalculator->calculate()->amount),
            // CPC art. 1016 alin. (1) lit. c: the sums and what they rest on. A
            // file with several invoices states each of them, with its own
            // interest, instead of one merged figure the debtor cannot check.
            'claim_items' => $items,
            'claim_item_accessories' => $this->accessories($case, $items, $rate),
        ];
    }

    /**
     * @param list<\App\Entity\ClaimItem> $items
     */
    private function accessories(LegalCase $case, array $items, ?string $rate): ?AggregatedAccessoryResult
    {
        if ($items === []) {
            return null;
        }

        try {
            return $this->accessoryAggregator->aggregate(
                items: $items,
                referenceDate: $this->accessoryReferenceDate($case),
                relationshipType: $case->getRelationshipType() ?? RelationshipType::COMERCIAL,
                penaltyType: $case->getPenaltyType() ?? PenaltyType::LEGAL_PENALIZATOARE,
                contractualDailyRate: $rate !== null ? (float) $rate : null,
                kind: InterestKind::PENALIZATOARE,
            );
        } catch (\DomainException) {
            // The petition still has to generate; without a per-position
            // accessory it falls back to the stored figure and the single row.
            return null;
        }
    }

    /**
     * The petition claims what the summons announced, so the accessory is shown
     * as of the notice date when there is one; otherwise as of today.
     */
    private function accessoryReferenceDate(LegalCase $case): \DateTimeImmutable
    {
        $notice = $case->getPaymentNoticeDate();

        return $notice !== null
            ? \DateTimeImmutable::createFromInterface($notice)
            : new \DateTimeImmutable();
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
