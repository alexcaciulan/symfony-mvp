<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Form\Wizard\Step2DebtorEntryType;

/**
 * The cross-step state of one wizard run.
 *
 * Public mutable properties, like the step DTOs: the steps read and write
 * them directly. `documentIds` is set in step 0; `creditor`, `debtors` and
 * `claim` hold the step DTOs once a step was saved; the rest carries the
 * library picks, the positions table and the decisions taken on the
 * disagreements between documents.
 *
 * The bag is stored serialized and a stored copy can be weeks old, written
 * by an earlier deployment. A property added to one of the DTOs it carries
 * must therefore be a plain property with a default, not a promoted
 * constructor parameter: a promoted one stays uninitialized on an older
 * payload and breaks the first step that reads it.
 */
final class WizardBag
{
    /**
     * @param list<int> $documentIds
     * @param ?list<ClaimItemRow> $claimItems
     * @param array<string, ConflictResolution> $conflictResolutions
     * @param array<string, array{fingerprint: string, at: string}> $conflictsAcknowledged
     */
    public function __construct(
        public array $documentIds = [],
        public ?Step1CreditorData $creditor = null,
        public ?Step2DebtorsData $debtors = null,
        public ?Step2DebtorsData $debtorBeforePick = null,
        public bool $debtorLibraryRecheck = false,
        public bool $creditorLibraryRecheck = false,
        public ?Step3ClaimData $claim = null,
        public ?array $claimItems = null,
        public bool $claimItemsTableConfirmed = false,
        public array $conflictResolutions = [],
        public array $conflictsAcknowledged = [],
    ) {}

    /**
     * Builds the bag from a stored array, dropping whatever does not have the
     * expected shape: a missing key takes its default, a value of the wrong
     * type is ignored, an unknown key is not carried.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $ids = $raw['documentIds'] ?? [];
        $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];

        $creditor = $raw['creditor'] ?? null;
        if (!$creditor instanceof Step1CreditorData) {
            $creditor = null;
        }

        $debtors = $raw['debtors'] ?? null;
        if (!$debtors instanceof Step2DebtorsData) {
            $debtors = null;
        }
        $debtorBeforePick = $raw['debtorBeforePick'] ?? null;
        if (!$debtorBeforePick instanceof Step2DebtorsData) {
            $debtorBeforePick = null;
        }

        $claim = $raw['claim'] ?? null;
        if (!$claim instanceof Step3ClaimData) {
            $claim = null;
        }

        $claimItems = $raw['claimItems'] ?? null;
        if (is_array($claimItems)) {
            $claimItems = array_values(array_filter(
                $claimItems,
                static fn (mixed $row): bool => $row instanceof ClaimItemRow,
            ));
        } else {
            $claimItems = null;
        }

        $acknowledged = [];
        foreach (is_array($raw['conflictsAcknowledged'] ?? null) ? $raw['conflictsAcknowledged'] : [] as $step => $record) {
            if (!is_string($step) || !is_array($record)) {
                continue;
            }
            if (!is_string($record['fingerprint'] ?? null) || !is_string($record['at'] ?? null)) {
                continue;
            }
            $acknowledged[$step] = ['fingerprint' => $record['fingerprint'], 'at' => $record['at']];
        }

        $resolutions = [];
        foreach (is_array($raw['conflictResolutions'] ?? null) ? $raw['conflictResolutions'] : [] as $key => $resolution) {
            if (is_string($key) && $resolution instanceof ConflictResolution) {
                $resolutions[$key] = $resolution;
            }
        }

        return new self(
            documentIds: $ids,
            creditor: $creditor,
            debtors: $debtors,
            debtorBeforePick: $debtorBeforePick,
            debtorLibraryRecheck: (bool) ($raw['debtorLibraryRecheck'] ?? false),
            creditorLibraryRecheck: (bool) ($raw['creditorLibraryRecheck'] ?? false),
            claim: $claim,
            claimItems: $claimItems,
            claimItemsTableConfirmed: (bool) ($raw['claimItemsTableConfirmed'] ?? false),
            conflictResolutions: $resolutions,
            conflictsAcknowledged: $acknowledged,
        );
    }

    /**
     * Why the saved debtors can no longer be taken as they are, as a flash
     * key, or null when they can. A run saved before a person type or the
     * second debtor was hidden can still hold them; it is sent back to the
     * debtor step rather than converted silently, so the lawyer re-enters the
     * party with the data the product accepts, or removes the extra debtor.
     */
    public function debtorsNoLongerOfferedReason(): ?string
    {
        $debtors = $this->debtors;
        if (!$debtors instanceof Step2DebtorsData) {
            return null;
        }
        if (count($debtors->debtors) > Step2DebtorsData::MAX_DEBTORS) {
            return 'wizard.step2.flash.too_many_debtors';
        }
        foreach ($debtors->debtors as $entry) {
            if ($entry->personType !== null
                && !in_array($entry->personType, Step2DebtorEntryType::OFFERED_PERSON_TYPES, true)) {
                return 'wizard.step2.flash.person_type_not_offered';
            }
        }

        return null;
    }

    /**
     * The stored shape of the bag. The key order is part of the contract: a
     * stored array is compared byte for byte by its serialization.
     *
     * @return array{documentIds: list<int>, creditor: ?Step1CreditorData, debtors: ?Step2DebtorsData, debtorBeforePick: ?Step2DebtorsData, debtorLibraryRecheck: bool, creditorLibraryRecheck: bool, claim: ?Step3ClaimData, claimItems: ?list<ClaimItemRow>, claimItemsTableConfirmed: bool, conflictResolutions: array<string, ConflictResolution>, conflictsAcknowledged: array<string, array{fingerprint: string, at: string}>}
     */
    public function toArray(): array
    {
        return [
            'documentIds' => $this->documentIds,
            'creditor' => $this->creditor,
            'debtors' => $this->debtors,
            'debtorBeforePick' => $this->debtorBeforePick,
            'debtorLibraryRecheck' => $this->debtorLibraryRecheck,
            'creditorLibraryRecheck' => $this->creditorLibraryRecheck,
            'claim' => $this->claim,
            'claimItems' => $this->claimItems,
            'claimItemsTableConfirmed' => $this->claimItemsTableConfirmed,
            'conflictResolutions' => $this->conflictResolutions,
            'conflictsAcknowledged' => $this->conflictsAcknowledged,
        ];
    }
}
