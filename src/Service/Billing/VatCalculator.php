<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\VatBreakdown;
use App\Entity\FiscalInvoiceLine;

/**
 * Exact VAT arithmetic for fiscal invoices. Money is handled in integer bani
 * (minor units) so there is no floating-point drift on the persisted totals,
 * and rounded to the nearest ban with PHP's default round() (half away from
 * zero, which equals half-up for the positive amounts of normal invoices and
 * mirrors the sign for negative storno lines). Inputs/outputs are DECIMAL strings.
 */
final class VatCalculator
{
    /**
     * Per-line totals: net = unitPriceNet * quantity, vat = net * rate%, gross = net + vat.
     *
     * @return array{net: string, vat: string, gross: string}
     */
    public function lineTotals(string $unitPriceNet, string $quantity, string $vatRate): array
    {
        $netBani = (int) round($this->toBani($unitPriceNet) * (float) $quantity);
        $vatBani = (int) round($netBani * (float) $vatRate / 100);
        $grossBani = $netBani + $vatBani;

        return [
            'net' => $this->fromBani($netBani),
            'vat' => $this->fromBani($vatBani),
            'gross' => $this->fromBani($grossBani),
        ];
    }

    /**
     * Aggregate totals over the invoice lines, grouped by VAT rate.
     *
     * @param iterable<FiscalInvoiceLine> $lines
     */
    public function breakdown(iterable $lines): VatBreakdown
    {
        $net = 0;
        $vat = 0;
        $gross = 0;
        $buckets = [];

        foreach ($lines as $line) {
            $lineNet = $this->toBani($line->getLineNet());
            $lineVat = $this->toBani($line->getLineVat());
            $lineGross = $this->toBani($line->getLineGross());

            $net += $lineNet;
            $vat += $lineVat;
            $gross += $lineGross;

            $rate = $line->getVatRate();
            $buckets[$rate] ??= ['rate' => $rate, 'net' => 0, 'vat' => 0, 'gross' => 0];
            $buckets[$rate]['net'] += $lineNet;
            $buckets[$rate]['vat'] += $lineVat;
            $buckets[$rate]['gross'] += $lineGross;
        }

        $byRate = array_map(fn (array $b): array => [
            'rate' => $b['rate'],
            'net' => $this->fromBani($b['net']),
            'vat' => $this->fromBani($b['vat']),
            'gross' => $this->fromBani($b['gross']),
        ], array_values($buckets));

        return new VatBreakdown($this->fromBani($net), $this->fromBani($vat), $this->fromBani($gross), $byRate);
    }

    private function toBani(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function fromBani(int $bani): string
    {
        return number_format($bani / 100, 2, '.', '');
    }
}
