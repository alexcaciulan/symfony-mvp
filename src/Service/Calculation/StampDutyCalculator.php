<?php

namespace App\Service\Calculation;

use App\DTO\Calculation\StampDutyResult;

final class StampDutyCalculator
{
    public function __construct(
        private float $fixedAmount,
        private string $lawVersion,
    ) {}

    public function calculate(): StampDutyResult
    {
        return new StampDutyResult(
            amount: $this->fixedAmount,
            lawVersion: $this->lawVersion,
        );
    }
}
