<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\Entity\LegalCase;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Calculation\ClaimInterestAggregator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The accessory of a saved case, per position, at the date the case states it.
 *
 * Before the summons that is the creation date, the date the wizard stored its
 * total at; from the summons on it is the summons date, the figure the debtor
 * was told and the petition claims. An earlier cutoff the lawyer chose wins.
 */
final class CaseAccessoryService
{
    public function __construct(
        private readonly ClaimInterestAggregator $accessoryAggregator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function referenceDate(LegalCase $case): \DateTimeImmutable
    {
        $notice = $case->getPaymentNoticeDate();

        return $case->accessoryReferenceDate($notice !== null
            ? \DateTimeImmutable::createFromInterface($notice)
            : $case->getCreatedAt());
    }

    /**
     * Brings the stored total to the case's reference date, so the case page
     * states what the summons does. Returns the previous and new figures when
     * the total moved, null when it stayed or could not be computed.
     *
     * @return array{from: ?string, to: string}|null
     */
    public function refreshStoredTotal(LegalCase $case): ?array
    {
        $accessories = $this->aggregate($case);
        if ($accessories === null) {
            return null;
        }
        $from = $case->getCalculatedInterest();
        $to = sprintf('%.2f', $accessories->total);
        if ($from !== null && abs((float) $from - $accessories->total) < 0.005) {
            return null;
        }
        $case->setCalculatedInterest($to);

        return ['from' => $from, 'to' => $to];
    }

    /** Null when the case has no counting position or the calculator refuses it. */
    public function aggregate(LegalCase $case): ?AggregatedAccessoryResult
    {
        $items = $case->getCountingClaimItems();
        if ($items === []) {
            return null;
        }
        $rate = $case->getContractualPenaltyRate();

        try {
            return $this->accessoryAggregator->aggregate(
                items: $items,
                referenceDate: $this->referenceDate($case),
                relationshipType: $case->getRelationshipType() ?? RelationshipType::COMERCIAL,
                penaltyType: $case->getPenaltyType() ?? PenaltyType::LEGAL_PENALIZATOARE,
                contractualDailyRate: $rate !== null ? (float) $rate : null,
                contractualPenaltyCapPercent: $case->contractualPenaltyCap(),
                contractDate: $case->contractDateImmutable(),
            );
        } catch (\DomainException | \RuntimeException | \InvalidArgumentException $e) {
            $this->logger->info('case.accessory.aggregate_failed', ['case' => $case->getId(), 'reason' => $e->getMessage()]);

            return null;
        }
    }
}
