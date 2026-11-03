<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Case\ClaimDetailsInput;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\LegalCase;
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
        $case->setPenaltyType($claimDto->penaltyType ?? PenaltyType::LEGAL_PENALIZATOARE);
        $case->setContractualPenaltyRate(
            $claimDto->contractualPenaltyRate !== null ? sprintf('%.3f', $claimDto->contractualPenaltyRate) : null
        );
        $case->setAccessoryCutoffDate($claimDto->accessoryCutoffDate);
        $case->setContractualPenaltyCapPercent(
            $claimDto->penaltyType === PenaltyType::CONTRACTUAL && $claimDto->contractualPenaltyCapPercent !== null
                ? sprintf('%.2f', $claimDto->contractualPenaltyCapPercent)
                : null,
        );
        // AI extraction can return a longer string than a column holds (a
        // contract's object phrase lands in contractReference, say), so cap
        // each to its column length rather than let the save 500.
        $case->setContractReference(StringCapper::cap($claimDto->contractReference, 255));
        $case->setContractObject(StringCapper::cap(self::trimmedOrNull($claimDto->contractObject), 255));
        $case->setPaymentNoticeNumber(StringCapper::cap(self::trimmedOrNull($claimDto->paymentNoticeNumber), 50));
        // The clause belongs to the contractual notice only; a statutory case
        // keeps none, so switching the type never leaves a stale clause behind.
        $isContractual = $case->getPenaltyType() === PenaltyType::CONTRACTUAL;
        $case->setPenaltyClauseArticle($isContractual ? StringCapper::cap(self::trimmedOrNull($claimDto->penaltyClauseArticle), 100) : null);
        $case->setPenaltyClauseText($isContractual ? self::trimmedOrNull($claimDto->penaltyClauseText) : null);
        $case->setContractualAccessoryLabel($isContractual ? $claimDto->contractualAccessoryLabel : null);
        $case->setClaimDescription($claimDto->description);
        $case->setInvoiceNumber(StringCapper::cap($claimDto->invoiceNumber, 100));
        $case->setInvoiceDate($claimDto->invoiceDate !== null ? \DateTime::createFromImmutable($claimDto->invoiceDate) : null);
        $case->setContractNumber(StringCapper::cap($claimDto->contractNumber, 100));
        $case->setContractDate($claimDto->contractDate !== null ? \DateTime::createFromImmutable($claimDto->contractDate) : null);
        $case->setLegalCostsFixed($claimDto->legalCostsFixed !== null ? sprintf('%.2f', $claimDto->legalCostsFixed) : null);
        $case->setLegalCostsCurrency($claimDto->legalCostsCurrency);
        $case->setLegalCostsSuccessPercent(
            $claimDto->legalCostsSuccessPercent !== null ? sprintf('%.2f', $claimDto->legalCostsSuccessPercent) : null
        );
    }

    /**
     * The details the case page lets the lawyer rewrite. The clause fields keep
     * the rule above: a statutory case holds none, whatever was typed.
     */
    public function applyDetails(LegalCase $case, ClaimDetailsInput $input): void
    {
        $case->setClaimDescription(self::trimmedOrNull($input->claimDescription));
        $case->setContractReference(StringCapper::cap(self::trimmedOrNull($input->contractReference), 255));
        $case->setPaymentNoticeNumber(StringCapper::cap(self::trimmedOrNull($input->paymentNoticeNumber), 50));
        $isContractual = $case->getPenaltyType() === PenaltyType::CONTRACTUAL;
        $case->setPenaltyClauseArticle($isContractual ? StringCapper::cap(self::trimmedOrNull($input->penaltyClauseArticle), 100) : null);
        $case->setPenaltyClauseText($isContractual ? self::trimmedOrNull($input->penaltyClauseText) : null);
    }

    public static function trimmedOrNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
