<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyResult;
use App\Entity\LegalCase;
use App\Enum\InterestKind;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\InterestCalculatorService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Assembles the financial/legal context for the payment-notice template.
 *
 * Keeps computation out of Twig (project convention: compute in PHP, render in
 * Twig). Branches on {@see PenaltyType}: legal penalty interest recomputes the
 * per-period breakdown via {@see InterestCalculatorService}; contractual penalty
 * uses the flat daily-rate {@see ContractualPenaltyCalculator}.
 *
 * Never throws: a missing due date or rate degrades gracefully to the stored
 * `calculatedInterest` lump sum so the document still generates.
 */
final class SummonsContextBuilder
{
    public function __construct(
        private readonly InterestCalculatorService $interestCalculator,
        private readonly ContractualPenaltyCalculator $penaltyCalculator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return array<string, mixed> extra keys merged into the template context
     */
    public function build(LegalCase $case): array
    {
        $penaltyType = $case->getPenaltyType() ?? PenaltyType::LEGAL_PENALIZATOARE;
        $principal = (float) ($case->getAmount() ?? '0');
        $refDate = $this->referenceDate($case);
        $dueDate = $this->dueDate($case);

        $interestResult = null;
        $penaltyResult = null;

        if ($penaltyType === PenaltyType::CONTRACTUAL) {
            $penaltyResult = $this->computeContractual($case, $principal, $dueDate, $refDate);
        } else {
            $interestResult = $this->computeLegalInterest($case, $principal, $dueDate, $refDate);
        }

        $accessoryTotal = $this->resolveAccessoryTotal($case, $interestResult, $penaltyResult);

        return [
            'penaltyType' => $penaltyType,
            'principal' => $principal,
            'currency' => $case->getCurrency(),
            'interestResult' => $interestResult,
            'penaltyResult' => $penaltyResult,
            'accessoryTotal' => $accessoryTotal,
            'grandTotal' => $principal + $accessoryTotal,
            'refDate' => $refDate,
            'invoiceDate' => $this->invoiceDate($case),
        ];
    }

    private function computeLegalInterest(
        LegalCase $case,
        float $principal,
        ?\DateTimeImmutable $dueDate,
        \DateTimeImmutable $refDate,
    ): ?InterestResult {
        if ($dueDate === null || $principal <= 0.0) {
            return null;
        }

        $relationshipType = $case->getRelationshipType() ?? RelationshipType::COMERCIAL;

        try {
            return $this->interestCalculator->calculate(
                amount: $principal,
                dueDate: $dueDate,
                referenceDate: $refDate,
                relationshipType: $relationshipType,
                kind: InterestKind::PENALIZATOARE,
                currency: $case->getCurrency(),
                invoiceDate: $this->invoiceDate($case),
            );
        } catch (\DomainException | \RuntimeException | \InvalidArgumentException $e) {
            $this->logger->warning('Summons legal interest computation skipped: {reason}', [
                'reason' => $e->getMessage(),
                'caseId' => $case->getId(),
            ]);

            return null;
        }
    }

    private function computeContractual(
        LegalCase $case,
        float $principal,
        ?\DateTimeImmutable $dueDate,
        \DateTimeImmutable $refDate,
    ): ?PenaltyResult {
        $rate = $case->getContractualPenaltyRate();
        if ($dueDate === null || $rate === null || $principal <= 0.0) {
            return null;
        }

        return $this->penaltyCalculator->calculate(
            amount: $principal,
            dailyRatePercent: (float) $rate,
            startDate: $dueDate,
            referenceDate: $refDate,
        );
    }

    /**
     * Prefer the freshly computed accessory; fall back to the stored
     * `calculatedInterest` lump sum when no breakdown could be produced.
     */
    private function resolveAccessoryTotal(
        LegalCase $case,
        ?InterestResult $interestResult,
        ?PenaltyResult $penaltyResult,
    ): float {
        if ($interestResult !== null) {
            return $interestResult->total;
        }

        if ($penaltyResult !== null) {
            return $penaltyResult->total;
        }

        return (float) ($case->getCalculatedInterest() ?? '0');
    }

    private function referenceDate(LegalCase $case): \DateTimeImmutable
    {
        $notice = $case->getPaymentNoticeDate();

        return $notice !== null
            ? \DateTimeImmutable::createFromInterface($notice)
            : new \DateTimeImmutable();
    }

    private function dueDate(LegalCase $case): ?\DateTimeImmutable
    {
        $dueDate = $case->getDueDate();

        return $dueDate !== null ? \DateTimeImmutable::createFromInterface($dueDate) : null;
    }

    private function invoiceDate(LegalCase $case): ?\DateTimeImmutable
    {
        $invoiceDate = $case->getInvoiceDate();

        return $invoiceDate !== null ? \DateTimeImmutable::createFromInterface($invoiceDate) : null;
    }
}
