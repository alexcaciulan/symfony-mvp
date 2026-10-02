<?php

namespace App\Service\Calculation;

use App\DTO\Calculation\InterestPeriod;
use App\DTO\Calculation\InterestResult;
use App\Entity\InterestRateConfig;
use App\Enum\InterestKind;
use App\Enum\RelationshipType;
use App\Repository\InterestRateConfigRepository;

final class InterestCalculatorService
{
    /**
     * The +8 pp margin between professionals (OG 13/2011 art. 3 para. 2^1) came
     * with Law 72/2013, in force from this date and only for contracts concluded
     * after it; before, the margin was 4 pp. A claim due earlier rests on an
     * older contract, and the rate this service applies would overstate it.
     */
    private const PROFESSIONAL_MARGIN_IN_FORCE = '2013-04-05';

    public function __construct(
        private InterestRateConfigRepository $rateRepository,
    ) {}

    public function calculate(
        float $amount,
        \DateTimeImmutable $dueDate,
        \DateTimeImmutable $referenceDate,
        RelationshipType $relationshipType,
        InterestKind $kind = InterestKind::PENALIZATOARE,
        string $currency = 'RON',
        ?\DateTimeImmutable $invoiceDate = null,
    ): InterestResult {
        if ($currency !== 'RON') {
            throw new \InvalidArgumentException('exception.calculation.currency_unsupported');
        }

        $dueDate = $this->normalize($dueDate);
        $referenceDate = $this->normalize($referenceDate);
        $invoiceDate = $invoiceDate !== null ? $this->normalize($invoiceDate) : null;

        if ($dueDate >= $referenceDate) {
            return new InterestResult(0.0, [], $dueDate, $referenceDate, $invoiceDate);
        }

        if ($relationshipType === RelationshipType::COMERCIAL && $kind === InterestKind::PENALIZATOARE
            && $dueDate < new \DateTimeImmutable(self::PROFESSIONAL_MARGIN_IN_FORCE)) {
            throw new \RuntimeException('exception.calculation.before_professional_margin');
        }

        $configs = $this->rateRepository->findAllValidUpTo($referenceDate);

        $startingConfig = $this->findStartingConfig($configs, $dueDate);
        if ($startingConfig === null) {
            throw new \RuntimeException('exception.calculation.interest_rate_missing');
        }

        $changes = array_values(array_filter(
            $configs,
            fn(InterestRateConfig $c) => $c->getValidFrom() > $dueDate && $c->getValidFrom() <= $referenceDate,
        ));

        $periods = [];
        $currentStart = $dueDate;
        $currentNbrRate = (float) $startingConfig->getReferenceRate();

        foreach ($changes as $change) {
            $periodEnd = $this->normalize($change->getValidFrom());
            $periods[] = $this->buildPeriod($amount, $currentStart, $periodEnd, $currentNbrRate, $relationshipType, $kind);
            $currentStart = $periodEnd;
            $currentNbrRate = (float) $change->getReferenceRate();
        }

        $periods[] = $this->buildPeriod($amount, $currentStart, $referenceDate, $currentNbrRate, $relationshipType, $kind);

        $total = array_sum(array_map(fn(InterestPeriod $p) => $p->periodInterest, $periods));

        return new InterestResult($total, $periods, $dueDate, $referenceDate, $invoiceDate);
    }

    private function buildPeriod(
        float $amount,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        float $nbrRate,
        RelationshipType $relationshipType,
        InterestKind $kind,
    ): InterestPeriod {
        // Z+1 is implicit in diff(): with start = dueDate the count covers the
        // half-open interval (dueDate, end], i.e. day after due date through end
        // inclusive (art. 1535 NCC); segments telescope to diff(dueDate, refDate).
        $days = (int) $start->diff($end)->days;
        $applicableRate = $relationshipType->applicableRate($nbrRate, $kind);
        $periodInterest = $amount * ($applicableRate / 100.0) * $days / 365.0;

        return new InterestPeriod(
            startDate: $start,
            endDate: $end,
            nbrRate: $nbrRate,
            applicableRate: $applicableRate,
            days: $days,
            periodInterest: $periodInterest,
        );
    }

    /** @param InterestRateConfig[] $configs */
    private function findStartingConfig(array $configs, \DateTimeImmutable $dueDate): ?InterestRateConfig
    {
        $match = null;
        foreach ($configs as $config) {
            if ($config->getValidFrom() <= $dueDate) {
                $match = $config;
            }
        }

        return $match;
    }

    private function normalize(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0, 0);
    }
}
