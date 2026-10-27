<?php

declare(strict_types=1);

namespace App\Util;

/**
 * What is still owed on a position once the payments tied to it come off.
 */
final class ClaimBalance
{
    /**
     * The unpaid rest in RON. The payment is in the invoice currency, so it is
     * taken off the RON value in the same proportion; never below zero.
     */
    public static function outstandingRon(float $amountRon, float $amount, float $paid): float
    {
        if ($paid <= 0.0 || $amount <= 0.0) {
            return $amountRon;
        }

        return round($amountRon * max(0.0, 1.0 - $paid / $amount), 2);
    }
}
