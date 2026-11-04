<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Extraction\WizardPrefillResult;
use App\DTO\Wizard\ConflictSettlement;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Service\Extraction\ConflictChoiceApplier;
use App\Service\Extraction\ConflictResolutionService;
use App\Service\Extraction\PrefillFromExtractionService;
use App\Service\Party\CuiNormalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * What the wizard does about the disagreements between its documents: which
 * ones a step shows, how the lawyer's decisions reach the step data, and
 * whether a step may advance past them.
 */
final class WizardConflictCoordinator
{
    /**
     * Fields the ANAF sync writes for a party. Once the lawyer has synced the
     * party, the register settles them: what the documents say about the seat,
     * the name or the Trade Registry number no longer decides anything, and
     * asking would only invite the lawyer to put a document's value back over
     * the register's.
     */
    public const ANAF_SETTLED_FIELDS = ['name', 'onrcNumber', 'address', 'county', 'locality'];

    public function __construct(
        private readonly PrefillFromExtractionService $prefill,
        private readonly ConflictResolutionService $conflictResolutions,
        private readonly ConflictChoiceApplier $conflictChoices,
        private readonly ValidatorInterface $validator,
        private readonly WizardDocumentReader $documentReader,
    ) {}

    /**
     * The conflicts left once the parties synced with ANAF are taken out, and
     * the choices made on the ones taken out are dropped, so they cannot be
     * applied over the register's values on the way to the filing.
     *
     * @param list<PrefillConflict> $conflicts
     * @return list<PrefillConflict>
     */
    public function withoutAnafSettled(WizardBag $bag, array $conflicts, ?Step1CreditorData $creditor, ?Step2DebtorsData $debtors): array
    {
        $kept = [];
        foreach ($conflicts as $conflict) {
            if ($this->isSettledByAnaf($conflict, $creditor, $debtors)) {
                unset($bag->conflictResolutions[$conflict->key()]);

                continue;
            }
            $kept[] = $conflict;
        }

        return $kept;
    }

    /**
     * The prefill and the disagreements one step is responsible for, with stale
     * decisions dropped.
     *
     * A choice is stored against a position in the conflict's option list, and
     * the documents of a session can change between two visits to a step. A pick
     * that quietly slid onto another value would be a decision nobody made, so
     * it is dropped and the conflict is put again. Re-aggregating after that is
     * what keeps the form and the panel describing the same state.
     *
     * @param list<ConflictScope> $scopes
     * @return array{0: WizardPrefillResult, 1: list<PrefillConflict>}
     */
    public function prefillWithConflicts(WizardBag $bag, array $scopes): array
    {
        $prefill = $this->prefill->aggregate($bag->documentIds, $bag->conflictResolutions);
        $conflicts = $this->conflictsInScope($prefill, $scopes);

        $reconciled = $this->conflictResolutions->reconcile($bag->conflictResolutions, $conflicts);
        if ($reconciled !== $bag->conflictResolutions) {
            // The documents changed under a choice of party: the debtor card
            // kept in the session describes a party that is no longer the
            // chosen one, so it goes, with the field choices made for it.
            if ($this->droppedPartyChoice($bag->conflictResolutions, $reconciled)) {
                $bag->debtors = null;
                $reconciled = array_filter(
                    $reconciled,
                    static fn (ConflictResolution $r): bool => $r->scope !== ConflictScope::DEBTOR,
                );
            }
            $bag->conflictResolutions = $reconciled;
            $prefill = $this->prefill->aggregate($bag->documentIds, $reconciled);
            $conflicts = $this->conflictsInScope($prefill, $scopes);
        }

        if (in_array(ConflictScope::DEBTOR_SET, $scopes, true)) {
            $conflicts = $this->withLibraryDebtor($bag, $prefill, $conflicts);
        }

        return [$prefill, $conflicts];
    }

    /**
     * With the debtor taken from the library, its identity is the library's:
     * what the documents disagree about in the debtor's fields no longer
     * decides anything and is not asked. What is asked is whether the company
     * the documents name is the one picked, when their CUIs differ.
     *
     * @param list<PrefillConflict> $conflicts
     * @return list<PrefillConflict>
     */
    public function withLibraryDebtor(WizardBag $bag, WizardPrefillResult $prefill, array $conflicts): array
    {
        $entry = $bag->debtors->debtors[0] ?? null;
        if (!$entry instanceof Step2DebtorEntry || $entry->debtorId === null) {
            return $conflicts;
        }

        $conflicts = array_values(array_filter(
            $conflicts,
            static fn (PrefillConflict $c): bool => $c->scope !== ConflictScope::DEBTOR,
        ));
        $documentsCui = CuiNormalizer::canonical($prefill->debtors->debtors[0]->cui ?? null);
        if ($documentsCui !== null && $documentsCui !== CuiNormalizer::canonical($entry->cui)) {
            $conflicts[] = new PrefillConflict(
                scope: ConflictScope::DEBTOR_SET,
                severity: ConflictSeverity::ERROR,
                messageKey: 'wizard.conflict.debtor_set.library_mismatch',
                field: 'libraryDebtor',
            );
        }

        return $conflicts;
    }

    /**
     * Moves what the lawyer chose into the data the step is about to keep.
     *
     * The panel posts alongside the form, so the fields were rendered before the
     * choice existed and the submission carries the value the ranking preferred.
     * Keeping that would make the audit entry state a value the filing
     * contradicts, which is worse than never offering the choice.
     *
     * A chosen value goes into the petition exactly like a typed one, so it has
     * to stand up to the same constraints; where it does not, the step refuses
     * rather than filing something the form would have rejected.
     *
     * @param mixed $rendered the step data as the page had drawn it
     * @param list<string> $rejected conflicts whose answer could not be kept
     */
    public function applyChosenValues(WizardBag $bag, ConflictScope $scope, mixed $rendered, mixed $dto, array &$rejected): bool
    {
        $resolutions = $bag->conflictResolutions;
        if ($this->conflictResolutions->ofScope($resolutions, $scope) === []) {
            return true;
        }

        $chosen = $this->prefill->aggregate($bag->documentIds, $resolutions);
        $moved = match (true) {
            $dto instanceof Step1CreditorData => $this->conflictChoices->applyToCreditor($dto, $rendered instanceof Step1CreditorData ? $rendered : null, $chosen->creditor, $resolutions),
            $dto instanceof Step2DebtorsData => $this->conflictChoices->applyToDebtors($dto, $rendered instanceof Step2DebtorsData ? $rendered : null, $chosen->debtors, $resolutions),
            $dto instanceof Step3ClaimData => $this->conflictChoices->applyToClaim($dto, $rendered instanceof Step3ClaimData ? $rendered : null, $chosen->claim, $resolutions),
            default => [],
        };

        // Each moved value against the constraints of the field it landed on. A
        // field that merely came along with the chosen document is put back the
        // way it was submitted: the choice was about another field and does not
        // justify filing a value the form refuses. A chosen field that cannot
        // stand is a decision that cannot be carried out, so it is undone and
        // put to the lawyer again rather than half applied.
        $stands = true;
        foreach ($moved as $change) {
            if (count($this->validator->validateProperty($change['target'], $change['property'])) === 0) {
                continue;
            }
            $change['target']->{$change['property']} = $change['previous'];
            if ($change['conflictKey'] === null) {
                continue;
            }
            unset($bag->conflictResolutions[$change['conflictKey']]);
            $rejected[] = $change['conflictKey'];
            $stands = false;
        }

        return $stands;
    }

    /**
     * The step data as it was rendered, detached from the object the form is
     * about to write into.
     */
    public function snapshotDto(mixed $dto): mixed
    {
        if ($dto instanceof Step2DebtorsData) {
            return new Step2DebtorsData(array_map(
                static fn (Step2DebtorEntry $entry): Step2DebtorEntry => clone $entry,
                $dto->debtors,
            ));
        }

        return is_object($dto) ? clone $dto : $dto;
    }

    /**
     * Whether the step may advance past what the documents disagree about.
     *
     * A blocking conflict would put a wrong party, a wrong sum or a wrong court
     * in the filing, so the wizard does not settle it by ranking. Where the
     * documents offer values to choose between, the step waits for the choice
     * itself; where they offer none, because the answer is whether this is one
     * file or two, all the lawyer can do is state they have read it. Both are
     * kept in the bag, so walking back to the step does not ask again.
     *
     * @param list<PrefillConflict> $conflicts
     */
    public function settleConflicts(Request $request, WizardBag $bag, string $step, array $conflicts): ConflictSettlement
    {
        $bag->conflictResolutions = $this->conflictResolutions->collect(
            $request->request->all('prefill_conflict'),
            $request->request->all('prefill_conflict_manual'),
            $conflicts,
            $bag->conflictResolutions,
        );

        if ($this->conflictResolutions->pendingChoices($conflicts, $bag->conflictResolutions) !== []) {
            return ConflictSettlement::refused('wizard.conflict.choice_required');
        }
        if ($this->conflictResolutions->unresolvableBlocks($conflicts) !== []) {
            return ConflictSettlement::refused('wizard.conflict.documents_change_required');
        }

        $pending = $this->conflictResolutions->pendingAcknowledgement($conflicts);
        if ($pending === []) {
            return ConflictSettlement::settled();
        }

        // Bound to the disagreements it was made about. A document uploaded
        // afterwards can raise another one, and a flag that stayed true would
        // carry the lawyer's statement onto something they never saw.
        $fingerprint = $this->conflictResolutions->acknowledgementFingerprint($pending);
        if (($bag->conflictsAcknowledged[$step]['fingerprint'] ?? null) === $fingerprint) {
            return ConflictSettlement::settled();
        }
        if ($request->request->getBoolean('prefill_conflicts_acknowledged')) {
            $bag->conflictsAcknowledged[$step] = [
                'fingerprint' => $fingerprint,
                'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];

            return ConflictSettlement::settled();
        }

        return ConflictSettlement::refused('wizard.conflict.acknowledge_required');
    }

    /**
     * What the lawyer stated they had read, by conflict, for the record.
     *
     * Only the blocking disagreements that offer nothing to choose between get
     * one: everywhere else the record is the choice itself.
     *
     * @param list<PrefillConflict> $conflicts
     * @return array<string, array{at: string, fingerprint: string}>
     */
    public function acknowledgements(WizardBag $bag, array $conflicts): array
    {
        $entries = [];
        foreach ($this->byStep($this->conflictResolutions->pendingAcknowledgement($conflicts)) as $step => $group) {
            $record = $bag->conflictsAcknowledged[$step] ?? null;
            if (!is_array($record)) {
                continue;
            }
            if (($record['fingerprint'] ?? null) !== $this->conflictResolutions->acknowledgementFingerprint($group)) {
                continue;
            }
            foreach ($group as $conflict) {
                $entries[$conflict->key()] = ['at' => (string) $record['at'], 'fingerprint' => (string) $record['fingerprint']];
            }
        }

        return $entries;
    }

    /**
     * The blocking disagreements nobody has settled, whichever step owns them.
     *
     * @param list<PrefillConflict> $conflicts
     * @return list<PrefillConflict>
     */
    public function unsettledConflicts(WizardBag $bag, array $conflicts): array
    {
        $unsettled = [
            ...$this->conflictResolutions->pendingChoices($conflicts, $bag->conflictResolutions),
            ...$this->conflictResolutions->unresolvableBlocks($conflicts),
        ];
        $acknowledged = $this->acknowledgements($bag, $conflicts);
        foreach ($this->conflictResolutions->pendingAcknowledgement($conflicts) as $conflict) {
            if (!isset($acknowledged[$conflict->key()])) {
                $unsettled[] = $conflict;
            }
        }

        return $unsettled;
    }

    /**
     * What the conflicts panel needs to render: the disagreements, the decisions
     * already taken, and the file name behind each value.
     *
     * The names are resolved here rather than carried on the option, because the
     * option is built by the aggregation from extraction payloads and has no
     * business loading document rows.
     *
     * @param list<PrefillConflict> $conflicts
     * @param list<string> $rejected keys whose answer could not be kept
     * @return array<string, mixed>
     */
    public function conflictViewVars(WizardBag $bag, array $conflicts, string $step, array $rejected = []): array
    {
        $pending = array_values(array_filter(
            $this->conflictResolutions->pendingAcknowledgement($conflicts),
            fn (PrefillConflict $c): bool => $this->stepOwning($c->scope) === $step,
        ));

        return [
            'prefill_conflicts' => $conflicts,
            'prefill_conflicts_acknowledged' => $pending !== []
                && ($bag->conflictsAcknowledged[$step]['fingerprint'] ?? null) === $this->conflictResolutions->acknowledgementFingerprint($pending),
            'prefill_conflict_resolutions' => $bag->conflictResolutions,
            'prefill_conflict_documents' => $this->documentReader->conflictDocumentNames($bag->documentIds),
            'prefill_conflict_rejected' => $rejected,
        ];
    }

    /**
     * Which of the parties named by the documents the debtor card shows (the
     * one the lawyer chose, or the first while nothing is chosen), by its label
     * rather than its position, which moves when documents are added.
     *
     * @param list<PrefillConflict> $conflicts
     */
    public function chosenParty(WizardBag $bag, array $conflicts): string
    {
        foreach ($conflicts as $conflict) {
            if ($conflict->messageKey === PrefillFromExtractionService::DEBTOR_CHOICE_MESSAGE) {
                $index = $bag->conflictResolutions[$conflict->key()]->optionIndex ?? 0;

                return ($conflict->options[$index] ?? $conflict->options[0])->displayValue();
            }
        }

        return '';
    }

    /**
     * The lawyer picked another party than the card showed. The submitted card
     * describes the previous one, so it is discarded, and so are the field
     * choices and checks made for that party; the step is then shown again
     * with the chosen party's data.
     */
    public function discardChosenParty(WizardBag $bag): void
    {
        $bag->debtors = null;
        $bag->conflictResolutions = array_filter(
            $bag->conflictResolutions,
            static fn (ConflictResolution $r): bool => $r->scope !== ConflictScope::DEBTOR,
        );
    }

    private function isSettledByAnaf(PrefillConflict $conflict, ?Step1CreditorData $creditor, ?Step2DebtorsData $debtors): bool
    {
        if (!in_array($conflict->field, self::ANAF_SETTLED_FIELDS, true)) {
            return false;
        }

        if ($conflict->scope === ConflictScope::CREDITOR) {
            return $creditor !== null && $creditor->anafCheckedAt !== null && $creditor->anafCheckedAt !== '';
        }

        if ($conflict->scope === ConflictScope::DEBTOR && $debtors !== null
            && preg_match('/^debtor-(\d+)$/', (string) $conflict->entityKey, $m) === 1) {
            $entry = $debtors->debtors[(int) $m[1]] ?? null;

            // A status-only check dates the entry too, but took none of its data.
            return $entry instanceof Step2DebtorEntry && $entry->anafDataSynced;
        }

        return false;
    }

    /**
     * The disagreements between documents that this step is responsible for
     * showing.
     *
     * @param list<ConflictScope> $scopes
     * @return list<PrefillConflict>
     */
    private function conflictsInScope(WizardPrefillResult $prefill, array $scopes): array
    {
        return array_values(array_filter(
            $prefill->conflicts,
            static fn (PrefillConflict $c): bool => in_array($c->scope, $scopes, true),
        ));
    }

    /**
     * @param array<string, ConflictResolution> $before
     * @param array<string, ConflictResolution> $after
     */
    private function droppedPartyChoice(array $before, array $after): bool
    {
        foreach (array_diff_key($before, $after) as $resolution) {
            if ($resolution->scope === ConflictScope::DEBTOR_SET && $resolution->field === 'debtors') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<PrefillConflict> $conflicts
     * @return array<string, list<PrefillConflict>>
     */
    private function byStep(array $conflicts): array
    {
        $grouped = [];
        foreach ($conflicts as $conflict) {
            $grouped[$this->stepOwning($conflict->scope)][] = $conflict;
        }

        return $grouped;
    }

    /**
     * The step that shows a scope, which is also the step whose acknowledgement
     * counts for it.
     */
    private function stepOwning(ConflictScope $scope): string
    {
        return match ($scope) {
            ConflictScope::CREDITOR => 'creditor',
            ConflictScope::DEBTOR, ConflictScope::DEBTOR_SET => 'debtors',
            ConflictScope::CLAIM, ConflictScope::CLAIM_ITEM => 'claim',
        };
    }
}
