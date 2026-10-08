<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Wizard\DocumentRemovalPlan;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\DTO\Wizard\ClaimItemRow;
use App\Enum\RemovalStepOutcome;
use App\Service\Case\ClaimItemFactory;
use App\Service\Party\CuiNormalizer;

/**
 * Keeps a removed document from surviving in the saved steps, without throwing
 * away the lawyer's work on parties the documents left still name.
 */
final class DocumentRemovalPlanner
{
    /** Claim fields the extraction fills; every other field is the lawyer's. */
    private const CLAIM_DOCUMENT_FIELDS = [
        'amount', 'currency', 'dueDate', 'legalGround', 'description', 'penaltyType',
        'contractualPenaltyRate', 'contractualPenaltyCapPercent', 'contractReference',
        'contractNumber', 'contractDate', 'invoiceNumber', 'invoiceDate',
    ];

    public function __construct(
        private readonly PrefillFromExtractionService $prefill,
        private readonly ClaimItemFactory $claimItems,
    ) {}

    /**
     * @param array<string, mixed> $bag the wizard session
     */
    public function plan(array $bag, int $removedId): DocumentRemovalPlan
    {
        $left = array_values(array_filter($bag['documentIds'], static fn (int $id): bool => $id !== $removedId));
        $after = $this->prefill->aggregate($left, $bag['conflictResolutions'] ?? []);

        $creditor = $bag['creditor'] ?? null;
        $creditorOutcome = $creditor instanceof Step1CreditorData
            ? $this->partyOutcome($creditor->autoFilled, $creditor->cui, [$after->creditor->cui])
            : RemovalStepOutcome::NOT_SAVED;

        $debtors = $bag['debtors'] ?? null;
        $savedDebtor = $debtors instanceof Step2DebtorsData ? ($debtors->debtors[0] ?? null) : null;
        $debtorOutcome = $savedDebtor instanceof Step2DebtorEntry
            ? $this->debtorsOutcome($debtors, $after->debtors)
            : RemovalStepOutcome::NOT_SAVED;

        $claim = $bag['claim'] ?? null;
        $claimAfter = $claim instanceof Step3ClaimData ? $this->refreshClaim($claim, $after->claim) : null;
        $principalBefore = $claim instanceof Step3ClaimData ? $this->principal($bag['claimItems'] ?? null) ?? $claim->amount : null;
        $principalAfter = $claim instanceof Step3ClaimData ? $this->principal($this->claimItems->collectRows($left)->primaryRows()) : null;
        $claimChanged = $claimAfter !== null && ($this->documentFieldsDiffer($claim, $claimAfter)
            || ($principalBefore !== null && $principalAfter !== null && abs($principalBefore - $principalAfter) >= 0.005));

        return new DocumentRemovalPlan(
            creditor: $creditorOutcome,
            creditorBefore: $creditor instanceof Step1CreditorData ? $creditor->name : null,
            creditorAfter: $after->creditor->name,
            debtor: $debtorOutcome,
            debtorBefore: $savedDebtor?->name,
            debtorAfter: $after->debtors->debtors[0]->name ?? null,
            claimRefreshed: $claimChanged,
            documentsLeft: count($left),
            claimAfter: $claimAfter,
            principalBefore: $principalBefore,
            principalAfter: $principalAfter,
        );
    }

    /**
     * @param array<string, mixed> $bag
     */
    public function apply(array &$bag, int $removedId, DocumentRemovalPlan $plan): void
    {
        $bag['documentIds'] = array_values(array_filter($bag['documentIds'], static fn (int $id): bool => $id !== $removedId));
        // The positions table is always rebuilt from the documents left.
        $bag['claimItems'] = null;
        $bag['claimItemsTableConfirmed'] = false;
        if ($plan->creditor === RemovalStepOutcome::REFILLED) {
            $bag['creditor'] = null;
        }
        if ($plan->debtor === RemovalStepOutcome::REFILLED) {
            $bag['debtors'] = null;
            $bag['debtorBeforePick'] = null;
        }
        if ($plan->claimAfter !== null) {
            $bag['claim'] = $plan->claimAfter;
        }
    }

    /**
     * @param list<string> $autoFilled
     * @param list<?string> $cuisLeft
     */
    private function partyOutcome(array $autoFilled, ?string $cui, array $cuisLeft): RemovalStepOutcome
    {
        // Typed by the lawyer or taken from the library: the documents never decided it.
        if ($autoFilled === []) {
            return RemovalStepOutcome::KEPT;
        }
        $mine = CuiNormalizer::canonical($cui);
        $left = array_filter(array_map(CuiNormalizer::canonical(...), $cuisLeft));

        return $mine !== null && in_array($mine, $left, true) ? RemovalStepOutcome::KEPT : RemovalStepOutcome::REFILLED;
    }

    private function debtorsOutcome(Step2DebtorsData $saved, Step2DebtorsData $after): RemovalStepOutcome
    {
        $cuisLeft = array_map(static fn (Step2DebtorEntry $e): ?string => $e->cui, $after->debtors);
        foreach ($saved->debtors as $entry) {
            if ($this->partyOutcome($entry->autoFilled, $entry->cui, $cuisLeft) === RemovalStepOutcome::REFILLED) {
                return RemovalStepOutcome::REFILLED;
            }
        }

        return RemovalStepOutcome::KEPT;
    }

    /**
     * @param list<ClaimItemRow>|null $rows
     */
    private function principal(?array $rows): ?float
    {
        if ($rows === null || $rows === []) {
            return null;
        }
        $total = 0.0;
        foreach ($rows as $row) {
            $total += $row->willCount() ? ($row->signedAmountRon() ?? 0.0) : 0.0;
        }

        return round($total, 2);
    }

    private function documentFieldsDiffer(Step3ClaimData $before, Step3ClaimData $after): bool
    {
        foreach (self::CLAIM_DOCUMENT_FIELDS as $field) {
            $a = $before->{$field};
            $b = $after->{$field};
            if ($a instanceof \DateTimeInterface || $b instanceof \DateTimeInterface) {
                if ($a?->format('Y-m-d') !== $b?->format('Y-m-d')) {
                    return true;
                }
            } elseif ($a != $b) {
                return true;
            }
        }

        return false;
    }

    /**
     * A field read from a document, then or now, takes what the documents left
     * say; a field the lawyer typed and no document supplies stays.
     */
    private function refreshClaim(Step3ClaimData $saved, Step3ClaimData $after): Step3ClaimData
    {
        $claim = clone $saved;
        foreach (self::CLAIM_DOCUMENT_FIELDS as $field) {
            if (in_array($field, $saved->autoFilled, true) || in_array($field, $after->autoFilled, true)) {
                $claim->{$field} = $after->{$field};
            }
        }
        $claim->autoFilled = $after->autoFilled;

        return $claim;
    }
}
