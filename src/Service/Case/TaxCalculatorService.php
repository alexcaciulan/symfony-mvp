<?php

namespace App\Service\Case;

class TaxCalculatorService
{
    private const PLATFORM_FEE = 29.90;

    /**
     * Calculate fees per OUG 80/2013:
     * - ≤2000 RON: 50 RON court fee
     * - 2001-10000 RON: 250 + 2% of (amount - 2000)
     *
     * @return array{courtFee: float, platformFee: float, totalFee: float}
     */
    public function calculate(float $claimAmount): array
    {
        if ($claimAmount <= 0 || $claimAmount > 10000) {
            throw new \InvalidArgumentException('Claim amount must be between 0.01 and 10000 RON.');
        }

        if ($claimAmount <= 2000) {
            $courtFee = 50.00;
        } else {
            $courtFee = 250.00 + ($claimAmount - 2000) * 0.02;
        }

        $platformFee = self::PLATFORM_FEE;
        $totalFee = $courtFee + $platformFee;

        return [
            'courtFee' => round($courtFee, 2),
            'platformFee' => round($platformFee, 2),
            'totalFee' => round($totalFee, 2),
        ];
    }
}
