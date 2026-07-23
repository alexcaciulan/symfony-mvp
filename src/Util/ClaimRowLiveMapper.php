<?php

declare(strict_types=1);

namespace App\Util;

use App\DTO\Wizard\ClaimItemRow;

/**
 * Flattens a {@see ClaimItemRow} into the plain-scalar array the step 3 live
 * component holds and re-hydrates on every edit. Kept as scalars (amount as a
 * dot-decimal string, dates as Y-m-d) so the values round-trip through the live
 * model without float or DateTime coercion, and so the amount input can carry a
 * Romanian comma the server parses back.
 */
final class ClaimRowLiveMapper
{
    /**
     * @param list<ClaimItemRow> $rows
     * @return list<array<string, mixed>>
     */
    public static function toArrays(array $rows): array
    {
        return array_map(self::toArray(...), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(ClaimItemRow $row): array
    {
        return [
            'key' => $row->dedupKey,
            'amount' => number_format($row->amount, 2, '.', ''),
            'fallbackAmount' => $row->amount,
            'currency' => $row->currency,
            'kind' => $row->kind->value,
            'number' => $row->documentNumber,
            'documentDate' => $row->documentDate?->format('Y-m-d'),
            'dueDate' => $row->dueDate?->format('Y-m-d'),
            'amountRon' => $row->amountRon,
            'exchangeRate' => $row->exchangeRate,
            'exchangeRateDate' => $row->exchangeRateDate?->format('Y-m-d'),
            'needsManualFx' => $row->needsManualFx,
            'paidAmount' => $row->paidAmount,
            'sourceDocumentId' => $row->sourceDocumentId,
            'cause' => $row->causeReference,
            'causeDocumentId' => $row->causeDocumentId,
            'description' => $row->description,
            'confidence' => $row->confidence,
            'confirmed' => $row->confirmed,
            'excluded' => $row->excluded,
            'warningKeys' => $row->warningKeys,
            'hasStatedDeduction' => $row->hasStatedDeduction,
        ];
    }
}
