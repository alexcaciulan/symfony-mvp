<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Case\ClaimDetailsInput;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\LegalCase;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\PenaltyType;
use App\Util\StringCapper;

/**
 * Writes the claim's descriptive and accessory configuration onto the case:
 * the wizard's step 3 at creation, the details dialog on the case page later.
 * The sums are never written here; they come from the positions.
 */
final class LegalCaseClaimFieldsMapper
{
    /**
     * Copy the summons accessory configuration (penalty type, contractual rate,
     * invoice/contract metadata, legal costs) from the claim DTO onto the case.
     * The document recomputes the actual accessory amounts from these at render.
     */
    public function applyAccessoryFields(LegalCase $case, Step3ClaimData $claimDto): void
    {
        $this->applyAccessoryConfiguration(
            $case,
            $claimDto->penaltyType,
            $claimDto->contractualPenaltyRate,
            $claimDto->contractualPenaltyCapPercent,
            $claimDto->contractualAccessoryLabel,
            $claimDto->accessoryCutoffDate,
        );
        // AI extraction can return a longer string than a column holds (a
        // contract's object phrase lands in contractReference, say), so cap
        // each to its column length rather than let the save 500.
        $case->setContractReference(StringCapper::cap($claimDto->contractReference, 255));
        $case->setPaymentNoticeNumber(StringCapper::cap(self::trimmedOrNull($claimDto->paymentNoticeNumber), 50));
        $this->applyClause($case, $claimDto->penaltyClauseArticle, $claimDto->penaltyClauseText);
        $case->setClaimDescription($claimDto->description);
        $case->setInvoiceNumber(StringCapper::cap($claimDto->invoiceNumber, 100));
        $case->setInvoiceDate($claimDto->invoiceDate !== null ? \DateTime::createFromImmutable($claimDto->invoiceDate) : null);
        $this->applyContract($case, $claimDto->contractNumber, $claimDto->contractDate, $claimDto->contractObject);
        $this->applyLegalCosts($case, $claimDto->legalCostsFixed, $claimDto->legalCostsCurrency, $claimDto->legalCostsSuccessPercent);
    }

    /**
     * The details the case page lets the lawyer rewrite. The accessory mode is
     * written first, so the clause rule reads the mode just chosen: switching
     * to the statutory mode clears the clause and the contract's accessory name.
     */
    public function applyDetails(LegalCase $case, ClaimDetailsInput $input): void
    {
        $this->applyAccessoryConfiguration(
            $case,
            $input->penaltyType,
            $input->contractualPenaltyRate,
            $input->contractualPenaltyCapPercent,
            $input->contractualAccessoryLabel,
            $input->accessoryCutoffDate,
        );
        $this->applyContract($case, $input->contractNumber, $input->contractDate, $input->contractObject);
        $case->setClaimDescription(self::trimmedOrNull($input->claimDescription));
        $case->setContractReference(StringCapper::cap(self::trimmedOrNull($input->contractReference), 255));
        $case->setPaymentNoticeNumber(StringCapper::cap(self::trimmedOrNull($input->paymentNoticeNumber), 50));
        $this->applyClause($case, $input->penaltyClauseArticle, $input->penaltyClauseText);
    }

    /** The recovery costs the payment order petition asks for. */
    public function applyLegalCosts(LegalCase $case, ?float $fixed, ?string $currency, ?float $successPercent): void
    {
        $case->setLegalCostsFixed($fixed !== null ? sprintf('%.2f', $fixed) : null);
        $case->setLegalCostsCurrency($currency);
        $case->setLegalCostsSuccessPercent($successPercent !== null ? sprintf('%.2f', $successPercent) : null);
    }

    /**
     * The accessory mode and its parameters. The rate is kept as typed on both
     * branches; the cap and the contract's name for the accessory exist on the
     * contractual branch only.
     */
    private function applyAccessoryConfiguration(
        LegalCase $case,
        ?PenaltyType $penaltyType,
        ?float $rate,
        ?float $cap,
        ?ContractualAccessoryLabel $label,
        ?\DateTimeImmutable $cutoff,
    ): void {
        $case->setPenaltyType($penaltyType ?? PenaltyType::LEGAL_PENALIZATOARE);
        $isContractual = $case->getPenaltyType() === PenaltyType::CONTRACTUAL;
        $case->setContractualPenaltyRate($rate !== null ? sprintf('%.3f', $rate) : null);
        $case->setContractualPenaltyCapPercent($isContractual && $cap !== null ? sprintf('%.2f', $cap) : null);
        $case->setContractualAccessoryLabel($isContractual ? $label : null);
        $case->setAccessoryCutoffDate($cutoff);
    }

    private function applyContract(LegalCase $case, ?string $number, ?\DateTimeImmutable $date, ?string $object): void
    {
        $case->setContractNumber(StringCapper::cap($number, 100));
        $case->setContractDate($date !== null ? \DateTime::createFromImmutable($date) : null);
        $case->setContractObject(StringCapper::cap(self::trimmedOrNull($object), 255));
    }

    /**
     * The clause belongs to the contractual notice only; a statutory case keeps
     * none, so switching the type never leaves a stale clause behind.
     */
    private function applyClause(LegalCase $case, ?string $article, ?string $text): void
    {
        $isContractual = $case->getPenaltyType() === PenaltyType::CONTRACTUAL;
        $case->setPenaltyClauseArticle($isContractual ? StringCapper::cap(self::trimmedOrNull($article), 100) : null);
        $case->setPenaltyClauseText($isContractual ? self::trimmedOrNull($text) : null);
    }

    public static function trimmedOrNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
