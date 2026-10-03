<?php

declare(strict_types=1);

namespace App\Service\Document\Summons;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyResult;
use App\DTO\Summons\ContractualPenaltyRow;
use App\DTO\Summons\LegalInterestRow;
use App\DTO\Summons\PrincipalRow;
use App\DTO\Summons\SummonsTables;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;

/**
 * Turns the computed accessory into the rows the payment notice prints.
 *
 * Pure: it only reads what the calculators already produced. Every row stays
 * reproducible by hand (balance × rate × days), which is why the rows are not
 * adjusted to add up to the total; when they do not, the template says so.
 *
 * The calculators count a half-open interval (start, end]. The first day that
 * accrues is therefore start + 1, and that is the day shown.
 */
final class SummonsTableBuilder
{
    /**
     * @param list<ClaimItem> $items positions counting towards the claim; empty
     *                               for a case that predates positions
     */
    public function build(
        LegalCase $case,
        array $items,
        ?AggregatedAccessoryResult $perItem,
        ?InterestResult $singleInterest,
        ?PenaltyResult $singlePenalty,
        float $accessoryTotal,
    ): SummonsTables {
        if ($items === []) {
            return $this->fromCase($case, $singleInterest, $singlePenalty, $accessoryTotal);
        }

        $principalRows = [];
        $interestRows = [];
        $penaltyRows = [];

        foreach ($items as $index => $item) {
            $principalRows[] = $this->principalRow($item);

            if ($perItem === null) {
                continue;
            }

            // Same key the aggregator uses, so unsaved positions still match.
            $key = $item->getId() ?? -((int) $index + 1);
            $balance = (float) ($item->getAmountRon() ?? '0');

            $interest = $perItem->interestByItemId[$key] ?? null;
            if ($interest !== null) {
                array_push($interestRows, ...$this->interestRows(
                    $interest, $item->getDocumentNumber(), $item->getDocumentDate(), $balance,
                ));
            }

            $penalty = $perItem->penaltyByItemId[$key] ?? null;
            if ($penalty !== null) {
                array_push($penaltyRows, ...$this->penaltyRows(
                    $penalty, $item->getDocumentNumber(), $item->getDocumentDate(), $balance,
                ));
            }
        }

        // No position could accrue on its own (no due dates, say), so the
        // accessory was computed on the case sum. Its basis still has to be
        // shown, or the notice states a total with nothing behind it.
        if ($interestRows === [] && $penaltyRows === [] && ($perItem === null || $perItem->isEmpty())) {
            $principal = (float) ($case->getAmount() ?? '0');
            $number = $case->getInvoiceNumber();
            $date = $this->immutable($case->getInvoiceDate());
            if ($singleInterest !== null) {
                $interestRows = $this->interestRows($singleInterest, $number, $date, $principal);
            }
            if ($singlePenalty !== null) {
                $penaltyRows = $this->penaltyRows($singlePenalty, $number, $date, $principal);
            }
        }

        return new SummonsTables(
            principalRows: $principalRows,
            legalInterestRows: $interestRows,
            contractualPenaltyRows: $penaltyRows,
            roundingNoteNeeded: $this->roundingNoteNeeded($interestRows, $penaltyRows, $accessoryTotal),
        );
    }

    private function fromCase(
        LegalCase $case,
        ?InterestResult $interest,
        ?PenaltyResult $penalty,
        float $accessoryTotal,
    ): SummonsTables {
        $principal = (float) ($case->getAmount() ?? '0');
        $number = $case->getInvoiceNumber();
        $date = $this->immutable($case->getInvoiceDate());

        $principalRows = [];
        if ($principal > 0.0) {
            $principalRows[] = new PrincipalRow(
                documentNumber: $number,
                documentDate: $date,
                dueDate: $this->immutable($case->getDueDate()),
                amount: $principal,
                originalAmount: $case->getOriginalAmount() !== null ? (float) $case->getOriginalAmount() : null,
                originalCurrency: $case->getOriginalCurrency(),
                exchangeRate: $case->getExchangeRate() !== null ? (float) $case->getExchangeRate() : null,
                exchangeRateDate: $this->immutable($case->getExchangeRateDate()),
            );
        }

        $interestRows = $interest !== null ? $this->interestRows($interest, $number, $date, $principal) : [];
        $penaltyRows = $penalty !== null ? $this->penaltyRows($penalty, $number, $date, $principal) : [];

        return new SummonsTables(
            principalRows: $principalRows,
            legalInterestRows: $interestRows,
            contractualPenaltyRows: $penaltyRows,
            roundingNoteNeeded: $this->roundingNoteNeeded($interestRows, $penaltyRows, $accessoryTotal),
        );
    }

    private function principalRow(ClaimItem $item): PrincipalRow
    {
        $converted = $item->getCurrency() !== 'RON' && $item->getExchangeRate() !== null;

        return new PrincipalRow(
            documentNumber: $item->getDocumentNumber(),
            documentDate: $item->getDocumentDate(),
            dueDate: $item->getDueDate(),
            amount: $item->signedAmountRon() ?? 0.0,
            originalAmount: $converted ? (float) $item->getAmount() : null,
            originalCurrency: $converted ? $item->getCurrency() : null,
            exchangeRate: $converted ? (float) $item->getExchangeRate() : null,
            exchangeRateDate: $converted ? $item->getExchangeRateDate() : null,
        );
    }

    /** @return list<LegalInterestRow> */
    private function interestRows(
        InterestResult $result,
        ?string $documentNumber,
        ?\DateTimeImmutable $documentDate,
        float $balance,
    ): array {
        $rows = [];
        foreach ($result->breakdown as $period) {
            if ($period->days <= 0) {
                continue;
            }
            $rows[] = new LegalInterestRow(
                documentNumber: $documentNumber,
                documentDate: $documentDate,
                balance: $balance,
                periodStart: $period->startDate->modify('+1 day'),
                periodEnd: $period->endDate,
                days: $period->days,
                nbrRate: $period->nbrRate,
                applicableRate: $period->applicableRate,
                interest: $period->periodInterest,
            );
        }

        return $rows;
    }

    /** @return list<ContractualPenaltyRow> */
    private function penaltyRows(
        PenaltyResult $result,
        ?string $documentNumber,
        ?\DateTimeImmutable $documentDate,
        float $balance,
    ): array {
        $rows = [];
        foreach ($result->breakdown as $period) {
            if ($period->days <= 0) {
                continue;
            }
            $rows[] = new ContractualPenaltyRow(
                documentNumber: $documentNumber,
                documentDate: $documentDate,
                balance: $balance,
                dueDate: $period->startDate,
                periodStart: $period->startDate->modify('+1 day'),
                periodEnd: $period->endDate,
                days: $period->days,
                dailyRate: $period->dailyRate,
                // A capped penalty is one period whose daily figure the
                // contract cuts down: the row states what is claimed.
                penalty: $result->isCapped() ? (float) $result->total : $period->periodPenalty,
                capped: $result->isCapped(),
            );
        }

        return $rows;
    }

    /**
     * @param list<LegalInterestRow>      $interestRows
     * @param list<ContractualPenaltyRow> $penaltyRows
     */
    private function roundingNoteNeeded(array $interestRows, array $penaltyRows, float $accessoryTotal): bool
    {
        if ($interestRows === [] && $penaltyRows === []) {
            return false;
        }

        $sum = 0.0;
        foreach ($interestRows as $row) {
            $sum += round($row->interest, 2);
        }
        foreach ($penaltyRows as $row) {
            $sum += round($row->penalty, 2);
        }

        return abs(round($sum, 2) - round($accessoryTotal, 2)) >= 0.005;
    }

    private function immutable(?\DateTimeInterface $date): ?\DateTimeImmutable
    {
        return $date !== null ? \DateTimeImmutable::createFromInterface($date) : null;
    }
}
