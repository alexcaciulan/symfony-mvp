<?php

namespace App\DTO\Extraction;

use App\Enum\LegalGroundCategory;
use App\Enum\PaymentTermAnchor;
use App\Enum\PenaltyType;

final readonly class ClaimExtraction
{
    /**
     * @param ?PenaltyType         $penaltyType            CONTRACTUAL only when the source carries an explicit penalty
     *                                                     clause with a daily rate; null otherwise (the wizard default applies)
     * @param ?float               $contractualPenaltyRate daily penalty rate as a percentage (e.g. 0.1 for "0,1%/zi"),
     *                                                     paired with PenaltyType::CONTRACTUAL
     * @param array<string, float> $confidencePerField     field name → confidence score 0..1
     * @param ?float               $contractualPenaltyCapPercent ceiling on the penalties as a percentage
     *                                                     of the sum they accrue on (e.g. 10 for 10%)
     * @param ?int                 $paymentTermDays        contractual payment term in days ("plata în 4 zile
     *                                                     de la facturare"), read from contracts only
     * @param ?PaymentTermAnchor   $paymentTermAnchor      the event the term runs from
     * @param ?bool                $paymentTermBusinessDays true when the term counts working days
     * @param ?list<array<string, mixed>> $payments     the payments a statement lists; null when no list
     *                                                     was read, so the statement's text is checked instead
     */
    public function __construct(
        public ?float $amount = null,
        public ?string $currency = null,
        public ?\DateTimeImmutable $dueDate = null,
        public ?LegalGroundCategory $legalGround = null,
        public ?string $description = null,
        public ?string $invoiceNumber = null,
        public ?\DateTimeImmutable $invoiceDate = null,
        public ?string $contractNumber = null,
        public ?\DateTimeImmutable $contractDate = null,
        public ?string $contractReference = null,
        public ?PenaltyType $penaltyType = null,
        public ?float $contractualPenaltyRate = null,
        public array $confidencePerField = [],
        public ?float $contractualPenaltyCapPercent = null,
        public ?int $paymentTermDays = null,
        public ?PaymentTermAnchor $paymentTermAnchor = null,
        public ?bool $paymentTermBusinessDays = null,
        public ?array $payments = null,
    ) {}
}
