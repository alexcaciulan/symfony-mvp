<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyResult;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\InterestKind;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Claim\ClaimCauseGrouper;
use App\Service\Document\Summons\SummonsTableBuilder;
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
        private ?ClaimInterestAggregator $accessoryAggregator = null,
        private readonly SummonsTableBuilder $tableBuilder = new SummonsTableBuilder(),
        private readonly ClaimCauseGrouper $causeGrouper = new ClaimCauseGrouper(),
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

        // The positions carry the accessory: each accrues from its own due date
        // (Civil Code art. 1535), which is the whole point of having them.
        $items = $case->getCountingClaimItems();
        $itemAccessories = $this->computePerItem($case, $items, $penaltyType, $refDate);

        if ($itemAccessories !== null && count($items) === 1) {
            // A single position is the case as it always was; keep handing the
            // template one result so nothing about an existing file changes.
            // Unsaved positions are keyed by -(index+1), as the aggregator does.
            $only = $itemAccessories->forItem($items[0]->getId() ?? -1);
            $interestResult = $only instanceof InterestResult ? $only : null;
            $penaltyResult = $only instanceof PenaltyResult ? $only : null;
        }

        if ($itemAccessories === null || $itemAccessories->isEmpty()) {
            if ($penaltyType === PenaltyType::CONTRACTUAL) {
                $penaltyResult = $this->computeContractual($case, $principal, $dueDate, $refDate);
            } else {
                $interestResult = $this->computeLegalInterest($case, $principal, $dueDate, $refDate);
            }
        }

        $accessoryTotal = $itemAccessories !== null && !$itemAccessories->isEmpty()
            ? $itemAccessories->total
            : $this->resolveAccessoryTotal($case, $interestResult, $penaltyResult);

        $isContractual = $penaltyType === PenaltyType::CONTRACTUAL;
        $tables = $this->tableBuilder->build($case, $items, $itemAccessories, $interestResult, $penaltyResult, $accessoryTotal);

        return [
            'penaltyType' => $penaltyType,
            'isContractual' => $isContractual,
            'summonsTables' => $tables,
            // The statutory branch has its own fixed wording; only the
            // contractual one names the accessory as the contract does.
            'accessoryLabel' => $isContractual
                ? ($case->getContractualAccessoryLabel() ?? ContractualAccessoryLabel::DEFAULT)
                : null,
            'dailyRateFormatted' => $this->formatDailyRate($case->getContractualPenaltyRate()),
            'calculationStart' => $this->calculationStart($tables->principalRows),
            'namedContract' => $this->namedContract($case, $items),
            'recipientDebtor' => $case->getDebtors()->first() ?: null,
            'noticeNumber' => $case->getPaymentNoticeNumber(),
            'principal' => $principal,
            'currency' => $case->getCurrency(),
            'interestResult' => $interestResult,
            'penaltyResult' => $penaltyResult,
            // CPC art. 1016 alin. (1) lit. c requires the sum and its basis to be
            // stated, so several invoices are listed one by one with their own
            // interest rather than merged into a single figure.
            'claimItems' => $items,
            'claimItemAccessories' => $itemAccessories,
            // One-line object of the whole claim, in the lawyer's own words.
            'claimDescription' => $case->getClaimDescription(),
            'accessoryTotal' => $accessoryTotal,
            'grandTotal' => $principal + $accessoryTotal,
            'refDate' => $refDate,
            'invoiceDate' => $this->invoiceDate($case),
            // FX conversion metadata (null for native-RON claims). Lets the
            // document state "X EUR × curs BNR Y (data Z) = W RON".
            'originalAmount' => $case->getOriginalAmount() !== null ? (float) $case->getOriginalAmount() : null,
            'originalCurrency' => $case->getOriginalCurrency(),
            'exchangeRate' => $case->getExchangeRate() !== null ? (float) $case->getExchangeRate() : null,
            'exchangeRateDate' => $case->getExchangeRateDate(),
        ];
    }

    /**
     * Accessory per position. Null when the case carries no positions (nothing
     * to iterate) so the caller falls back to the single-sum computation.
     *
     * @param list<ClaimItem> $items
     */
    private function computePerItem(
        LegalCase $case,
        array $items,
        PenaltyType $penaltyType,
        \DateTimeImmutable $refDate,
    ): ?AggregatedAccessoryResult {
        if ($items === []) {
            return null;
        }

        $rate = $case->getContractualPenaltyRate();

        try {
            return $this->aggregator()->aggregate(
                items: $items,
                referenceDate: $refDate,
                relationshipType: $case->getRelationshipType() ?? RelationshipType::COMERCIAL,
                penaltyType: $penaltyType,
                contractualDailyRate: $rate !== null ? (float) $rate : null,
                kind: InterestKind::PENALIZATOARE,
            );
        } catch (\DomainException $e) {
            // This builder's contract is that the document still generates. An
            // unsupported claim type degrades to the stored lump sum, as a
            // missing rate always has.
            $this->logger->warning('Summons per-position accessory skipped: {reason}', [
                'reason' => $e->getMessage(),
                'caseId' => $case->getId(),
            ]);

            return null;
        }
    }

    private function aggregator(): ClaimInterestAggregator
    {
        return $this->accessoryAggregator ??= new ClaimInterestAggregator(
            $this->interestCalculator,
            $this->penaltyCalculator,
            $this->logger,
        );
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
        // Rounded once, as the per-position aggregate is, so the totals the
        // notice prints are money and not raw floats.
        if ($interestResult !== null) {
            return round($interestResult->total, 2);
        }

        if ($penaltyResult !== null) {
            return round($penaltyResult->total, 2);
        }

        return (float) ($case->getCalculatedInterest() ?? '0');
    }

    /**
     * The contract the claim rests on, when there is exactly one and it is
     * identified. Several causes (CPC art. 99) or an unnamed contract leave the
     * notice to speak of the invoices alone.
     *
     * @param list<ClaimItem> $items
     *
     * @return array{number: ?string, date: ?\DateTimeInterface}|null
     */
    private function namedContract(LegalCase $case, array $items): ?array
    {
        $number = $case->getContractNumber();
        $date = $case->getContractDate();

        if (($number === null || trim($number) === '') && $date === null) {
            return null;
        }

        if ($items !== [] && !$this->causeGrouper->isSingleCause($items)) {
            return null;
        }

        return ['number' => $number !== null && trim($number) !== '' ? $number : null, 'date' => $date];
    }

    /**
     * The first day accessories accrue on, when there is a single document to
     * speak of. With several, each accrues from its own due date and the
     * notice says so in words.
     *
     * @param list<\App\DTO\Summons\PrincipalRow> $rows
     */
    private function calculationStart(array $rows): ?\DateTimeImmutable
    {
        if (count($rows) !== 1 || $rows[0]->dueDate === null) {
            return null;
        }

        return $rows[0]->dueDate->modify('+1 day');
    }

    /** "0.100" reads "0,1", "0.015" reads "0,015": no trailing zeros. */
    private function formatDailyRate(?string $rate): ?string
    {
        if ($rate === null) {
            return null;
        }

        $formatted = rtrim(rtrim(number_format((float) $rate, 3, ',', '.'), '0'), ',');

        return $formatted !== '' ? $formatted : null;
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
