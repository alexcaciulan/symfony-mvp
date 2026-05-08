<?php

namespace App\DTO\Calculation;

final readonly class StampDutyResult
{
    public function __construct(
        public float $amount,
        public string $lawVersion,
    ) {}
}
