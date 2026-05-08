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
    ): InterestResult {
        if ($currency !== 'RON') {
            throw new \InvalidArgumentException('exception.calculation.currency_unsupported');
        }

        $dueDate = $this->normalize($dueDate);
        $referenceDate = $this->normalize($referenceDate);

        if ($dueDate >= $referenceDate) {
            return new InterestResult(0.0, []);
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

        return new InterestResult($total, $periods);
    }

    private function buildPeriod(
        float $amount,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        float $nbrRate,
        RelationshipType $relationshipType,
        InterestKind $kind,
    ): InterestPeriod {
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
