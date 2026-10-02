<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Extraction\AggregatedFields;
use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\DocumentClassification;
use App\DTO\Extraction\ExtractedDocumentData;
use App\DTO\Extraction\FieldSource;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Extraction\WizardPrefillResult;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\Document;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Enum\FieldGroup;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Repository\DocumentRepository;

/**
 * Turns the extraction payloads of a wizard session into the prefilled step
 * forms.
 *
 * The aggregation itself lives in {@see CoherentAggregator}: one source
 * document per group of fields, taken whole, so the parties in the filing are
 * parties some document actually describes. This class reads the payloads,
 * splits the debtor candidates into as many parties as the documents name, and
 * maps what comes back onto the wizard DTOs.
 *
 * **Structural contract**:
 *   - `aggregateForCreditor()` / `aggregateForDebtor()` / `aggregateForClaim()`
 *     always return a populated DTO instance, possibly with every nullable
 *     field left null when no document carries an above-threshold value.
 *   - `aggregateForDebtors()` always returns at least one entry, so the form
 *     renders the primary debtor card without a "no debtor" branch, and one
 *     entry per distinct party beyond that.
 *   - `Step3ClaimData::$dueDate` is only marked `autoFilled` when the raw value
 *     parsed into a `\DateTimeImmutable`; malformed dates are dropped and the
 *     lawyer picks manually.
 *
 * Resilient to malformed payloads: missing keys, null sections and partial
 * confidence maps are all tolerated, and a document whose row has since been
 * deleted is skipped silently.
 */
final class PrefillFromExtractionService
{
    /**
     * Below this threshold a field is not confident enough to prefill. Kept
     * here as well as on the aggregator because callers and tests read it as
     * the prefill contract rather than as an aggregation detail.
     */
    public const MIN_CONFIDENCE = CoherentAggregator::MIN_CONFIDENCE;

    /** Message of the conflict asking which party the case is against. */
    public const DEBTOR_CHOICE_MESSAGE = 'wizard.conflict.debtor_set.choose';

    /** @var list<string> */
    private const CREDITOR_FIELDS = [
        'personType', 'name', 'cui', 'personalId', 'onrcNumber', 'address', 'county',
        'locality', 'iban', 'legalRepresentative', 'bankName',
    ];

    /** @var list<string> */
    private const DEBTOR_FIELDS = [
        'personType', 'name', 'cui', 'personalId', 'onrcNumber', 'address', 'county',
        'locality', 'email', 'phone', 'iban', 'administrator',
    ];

    /** @var list<string> */
    private const CLAIM_FIELDS = [
        'amount', 'currency', 'dueDate', 'legalGround', 'description', 'invoiceNumber',
        'invoiceDate', 'contractNumber', 'contractDate', 'contractReference',
        'penaltyType', 'contractualPenaltyRate', 'contractualPenaltyCapPercent',
    ];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly CoherentAggregator $aggregator = new CoherentAggregator(),
        private readonly ConflictResolutionService $resolutionService = new ConflictResolutionService(),
    ) {}

    /**
     * Everything the wizard can prefill from one session's documents, with the
     * conflicts and the provenance that go with it.
     *
     * @param list<int> $documentIds
     * @param array<string, ConflictResolution> $resolutions what the lawyer has
     *        already decided about the disagreements, applied over the ranking
     */
    public function aggregate(array $documentIds, array $resolutions = []): WizardPrefillResult
    {
        $documents = $this->loadDocuments($documentIds);

        $creditor = $this->aggregator->aggregate(
            $this->creditorSources($documents),
            $this->fieldGroups(self::CREDITOR_FIELDS, FieldGroup::partyFieldMap()),
            ConflictScope::CREDITOR,
            null,
            $this->resolutionService->pinsFor($resolutions, ConflictScope::CREDITOR),
        );
        $claim = $this->aggregator->aggregate(
            $this->claimSources($documents),
            $this->fieldGroups(self::CLAIM_FIELDS, FieldGroup::claimFieldMap()),
            ConflictScope::CLAIM,
            null,
            $this->resolutionService->pinsFor($resolutions, ConflictScope::CLAIM),
        );

        $clusters = $this->clusterDebtors($documents);
        $debtorEntries = [];
        $debtorConflicts = [];
        $debtorProvenance = [];

        // One debtor per case for now: when the documents name several, the
        // lawyer picks the one this case is against. The other parties are not
        // dropped in silence, they stay on the choice (and in its record).
        $partyChoice = null;
        $chosen = 0;
        $chosenClusters = $clusters;
        if (count($clusters) > Step2DebtorsData::MAX_DEBTORS && Step2DebtorsData::MAX_DEBTORS === 1) {
            $partyChoice = $this->debtorChoice($clusters);
            $chosen = $resolutions[$partyChoice->key()]->optionIndex ?? 0;
            if (!isset($clusters[$chosen])) {
                $chosen = 0;
            }
            $chosenClusters = [$clusters[$chosen]];
            $debtorConflicts[] = $partyChoice;
        }

        foreach ($chosenClusters as $index => $cluster) {
            $aggregated = $this->aggregator->aggregate(
                $cluster,
                $this->fieldGroups(self::DEBTOR_FIELDS, FieldGroup::partyFieldMap()),
                ConflictScope::DEBTOR,
                'debtor-' . $index,
                $this->resolutionService->pinsFor($resolutions, ConflictScope::DEBTOR, 'debtor-' . $index),
            );
            $debtorEntries[] = $this->buildDebtorEntry($aggregated);
            foreach ($aggregated->conflicts as $conflict) {
                $debtorConflicts[] = $conflict;
            }
            foreach ($aggregated->provenance as $field => $documentId) {
                $debtorProvenance['debtor-' . $index . '.' . $field] = $documentId;
            }
        }
        if ($debtorEntries === []) {
            $debtorEntries[] = $this->buildDebtorEntry(new AggregatedFields());
        }

        // Over the product cap the extra parties are reported, never dropped in
        // silence: a debtor that disappears between the documents and the form
        // is a party the lawyer never learns the documents named.
        if ($partyChoice === null && count($debtorEntries) > Step2DebtorsData::MAX_DEBTORS) {
            $debtorConflicts[] = new PrefillConflict(
                scope: ConflictScope::DEBTOR_SET,
                severity: ConflictSeverity::ERROR,
                messageKey: 'wizard.conflict.debtor_set.too_many',
                field: 'debtors',
            );
            $debtorEntries = array_slice($debtorEntries, 0, Step2DebtorsData::MAX_DEBTORS);
        } elseif ($partyChoice === null && count($debtorEntries) > 1) {
            $debtorConflicts[] = new PrefillConflict(
                scope: ConflictScope::DEBTOR_SET,
                severity: ConflictSeverity::INFO,
                messageKey: 'wizard.conflict.debtor_set.multiple',
                field: 'debtors',
            );
        }

        // Sums owed by different debtors are different claims. A payment order
        // adding them together states a debt nobody owes, and nothing in the
        // documents says the debtors answer for one another, so the wizard must
        // not settle it.
        $claimBearing = $this->claimBearingIds($documents);
        $spanMessage = null;
        if ($partyChoice !== null) {
            // With one debtor chosen, a claim document is fine when the chosen
            // party is named in it, even next to others (joint debtors on one
            // contract: the creditor may pursue either). It is not when it names
            // only other parties: those sums are owed by someone else.
            [$withChosen, $withoutChosen] = $this->claimDocumentsByChosenParty($claimBearing, $clusters, $chosen);
            if ($withoutChosen > 0) {
                $spanMessage = $withChosen > 0
                    ? 'wizard.conflict.debtor_set.claims_span_debtors'
                    : 'wizard.conflict.debtor_set.claims_of_another_party';
            }
        } elseif ($this->claimsSpanDebtors($claimBearing, $clusters)) {
            $spanMessage = 'wizard.conflict.debtor_set.claims_span_debtors';
        }
        if ($spanMessage !== null) {
            // Nothing to acknowledge: a sum owed by another debtor would enter
            // this case. The documents of that debtor have to leave the case.
            $debtorConflicts[] = new PrefillConflict(
                scope: ConflictScope::DEBTOR_SET,
                severity: ConflictSeverity::ERROR,
                messageKey: $spanMessage,
                field: 'debtors',
                acknowledgeable: false,
            );
        }

        return new WizardPrefillResult(
            creditor: $this->buildCreditorData($creditor),
            debtors: new Step2DebtorsData($debtorEntries),
            claim: $this->buildClaimData($claim),
            conflicts: array_values([
                ...$creditor->conflicts,
                ...$debtorConflicts,
                ...$this->claimConflicts($claim->conflicts, count($claimBearing)),
            ]),
            provenance: [
                'creditor' => $creditor->provenance,
                'debtors' => $debtorProvenance,
                'claim' => $claim->provenance,
            ],
        );
    }

    /**
     * @param list<int> $documentIds
     * @param array<string, ConflictResolution> $resolutions
     */
    public function aggregateForCreditor(array $documentIds, array $resolutions = []): Step1CreditorData
    {
        return $this->aggregate($documentIds, $resolutions)->creditor;
    }

    /**
     * The primary debtor, for the surfaces that still show exactly one (the
     * step-0 side card, the overview preview).
     *
     * @param list<int> $documentIds
     * @param array<string, ConflictResolution> $resolutions
     */
    public function aggregateForDebtor(array $documentIds, array $resolutions = []): Step2DebtorEntry
    {
        return $this->aggregate($documentIds, $resolutions)->debtors->debtors[0];
    }

    /**
     * One entry per party the documents describe, capped at the product limit.
     *
     * @param list<int> $documentIds
     * @param array<string, ConflictResolution> $resolutions
     */
    public function aggregateForDebtors(array $documentIds, array $resolutions = []): Step2DebtorsData
    {
        return $this->aggregate($documentIds, $resolutions)->debtors;
    }

    /**
     * @param list<int> $documentIds
     * @param array<string, ConflictResolution> $resolutions
     */
    public function aggregateForClaim(array $documentIds, array $resolutions = []): Step3ClaimData
    {
        return $this->aggregate($documentIds, $resolutions)->claim;
    }

    /**
     * @param list<Document> $documents
     * @return list<FieldSource>
     */
    private function creditorSources(array $documents): array
    {
        $sources = [];
        foreach ($documents as $ordinal => $document) {
            $section = $document->getExtractedData()['creditor'] ?? null;
            if (!is_array($section)) {
                continue;
            }
            $sources[] = $this->toSource($document, $ordinal, $section, self::CREDITOR_FIELDS);
        }

        return $sources;
    }

    /**
     * @param list<Document> $documents
     * @return list<FieldSource>
     */
    private function claimSources(array $documents): array
    {
        $sources = [];
        foreach ($documents as $ordinal => $document) {
            $section = $document->getExtractedData()['claim'] ?? null;
            if (!is_array($section)) {
                continue;
            }
            $sources[] = $this->toSource($document, $ordinal, $section, self::CLAIM_FIELDS);
        }

        return $sources;
    }

    /**
     * The debtor candidates, split into one cluster per party.
     *
     * Clustering, not "the best debtor": several documents describing one
     * company must end up as one card with the union of what they say, and two
     * documents describing two companies must end up as two cards. The
     * threshold for declaring two candidates to be the same party is stricter
     * than the one for filling a gap, because the two mistakes do not cost the
     * same. Splitting one debtor in two costs the lawyer two clicks; merging
     * two debtors into one produces a filing against a party nobody identified.
     *
     * @param list<Document> $documents
     * @return list<list<FieldSource>>
     */
    private function clusterDebtors(array $documents): array
    {
        $clusters = [];
        foreach ($documents as $ordinal => $document) {
            $payload = $document->getExtractedData();
            if (!is_array($payload)) {
                continue;
            }
            foreach (ExtractedDocumentData::debtorPayloadsOf($payload) as $section) {
                $source = $this->toSource($document, $ordinal, $section, self::DEBTOR_FIELDS);
                if ($source->values === []) {
                    continue;
                }

                // First match wins, and the documents arrive ordered by id, so
                // the same set of files always produces the same clusters in
                // the same order.
                $placed = false;
                foreach ($clusters as $index => $cluster) {
                    if ($this->aggregator->isSameIdentity($cluster[0], $source)) {
                        $clusters[$index][] = $source;
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) {
                    $clusters[] = [$source];
                }
            }
        }

        return $clusters;
    }

    /**
     * The claim disagreements worth showing.
     *
     * Several invoices state several sums and several due dates, and that is
     * what a file with several invoices looks like: reporting it as a
     * disagreement would put a blocking conflict on the ordinary case and teach
     * the lawyer to tick the box without reading. Where one invoice is stated
     * twice with two sums, the positions table raises it per position.
     *
     * @param list<PrefillConflict> $conflicts
     * @return list<PrefillConflict>
     */
    private function claimConflicts(array $conflicts, int $claimBearingCount): array
    {
        if ($claimBearingCount < 2) {
            return $conflicts;
        }

        $perPosition = FieldGroup::claimFieldMap();

        return array_values(array_filter(
            $conflicts,
            static fn (PrefillConflict $c): bool => $c->field === null
                || ($perPosition[$c->field] ?? null) !== FieldGroup::CLAIM_AMOUNT,
        ));
    }

    /**
     * The documents that state a debt of their own, by id.
     *
     * @param list<Document> $documents
     * @return array<int, true>
     */
    private function claimBearingIds(array $documents): array
    {
        $ids = [];
        foreach ($documents as $ordinal => $document) {
            $claim = $document->getExtractedData()['claim'] ?? null;
            if (is_array($claim) && is_numeric($claim['amount'] ?? null) && (float) $claim['amount'] !== 0.0) {
                $ids[$document->getId() ?? $ordinal] = true;
            }
        }

        return $ids;
    }

    /**
     * Whether the documents that state a debt describe more than one debtor.
     *
     * @param array<int, true> $claimBearing
     * @param list<list<FieldSource>> $clusters
     */
    /**
     * How many documents that state a debt name the chosen party, and how many
     * name only other parties. A claim document naming no debtor at all counts
     * in neither.
     *
     * @param array<int, true> $claimBearing
     * @param list<list<FieldSource>> $clusters
     * @return array{int, int}
     */
    private function claimDocumentsByChosenParty(array $claimBearing, array $clusters, int $chosen): array
    {
        /** @var array<int, bool> $namesChosen claim document id to whether it names the chosen party */
        $namesChosen = [];
        foreach ($clusters as $index => $cluster) {
            foreach ($cluster as $source) {
                if (isset($claimBearing[$source->documentId])) {
                    $namesChosen[$source->documentId] = ($namesChosen[$source->documentId] ?? false) || $index === $chosen;
                }
            }
        }

        $with = count(array_filter($namesChosen));

        return [$with, count($namesChosen) - $with];
    }

    private function claimsSpanDebtors(array $claimBearing, array $clusters): bool
    {
        if (count($clusters) < 2) {
            return false;
        }

        $touched = 0;
        foreach ($clusters as $cluster) {
            foreach ($cluster as $source) {
                if (isset($claimBearing[$source->documentId])) {
                    ++$touched;
                    break;
                }
            }
        }

        return $touched > 1;
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string> $fields
     */
    private function toSource(Document $document, int $ordinal, array $section, array $fields): FieldSource
    {
        $confidence = is_array($section['confidencePerField'] ?? null) ? $section['confidencePerField'] : [];
        $values = [];
        $scores = [];
        foreach ($fields as $field) {
            $value = $section[$field] ?? null;
            if ($value === null || !is_numeric($confidence[$field] ?? null)) {
                // A value with no score is not read: the prompts are explicit
                // that a scored field is the only kind that gets used, and
                // guessing a score here would silently promote one.
                continue;
            }
            $values[$field] = $value;
            $scores[$field] = (float) $confidence[$field];
        }

        return new FieldSource(
            // The persisted id where there is one, the position in the ordered
            // set otherwise, so the tie-break stays total either way.
            documentId: $document->getId() ?? $ordinal,
            documentType: $this->effectiveType($document),
            values: $values,
            confidence: $scores,
        );
    }

    /**
     * The type to reason about: what the lawyer declared, or what the
     * extraction detected when they declared nothing and the detection was
     * confident enough to be adopted.
     */
    private function effectiveType(Document $document): ?DocumentType
    {
        $declared = $document->getDocumentType();
        if ($declared !== DocumentType::ALT_DOCUMENT) {
            return $declared;
        }
        $detected = $document->getDetectedType();
        $confidence = $document->getDetectedTypeConfidence();
        if ($detected === null || $confidence === null) {
            return null;
        }

        return (float) $confidence > DocumentClassification::ADOPTION_THRESHOLD ? $detected : null;
    }

    /**
     * @param list<string> $fields
     * @param array<string, FieldGroup> $map
     * @return array<string, FieldGroup>
     */
    private function fieldGroups(array $fields, array $map): array
    {
        $groups = [];
        foreach ($fields as $field) {
            if (isset($map[$field])) {
                $groups[$field] = $map[$field];
            }
        }

        return $groups;
    }

    private function buildCreditorData(AggregatedFields $aggregated): Step1CreditorData
    {
        $v = $aggregated->values;

        return new Step1CreditorData(
            personType: $this->toPersonType($v['personType'] ?? null),
            name: $this->toStringOrNull($v['name'] ?? null),
            cui: $this->toStringOrNull($v['cui'] ?? null),
            personalId: $this->toStringOrNull($v['personalId'] ?? null),
            onrcNumber: $this->toStringOrNull($v['onrcNumber'] ?? null),
            address: $this->toStringOrNull($v['address'] ?? null),
            addressCounty: $this->toStringOrNull($v['county'] ?? null),
            addressLocality: $this->toStringOrNull($v['locality'] ?? null),
            iban: $this->toStringOrNull($v['iban'] ?? null),
            legalRepresentative: $this->toStringOrNull($v['legalRepresentative'] ?? null),
            bankName: $this->toStringOrNull($v['bankName'] ?? null),
            autoFilled: $this->remapAddressFields($aggregated->autoFilled),
        );
    }

    /**
     * Extraction emits `county`/`locality`; both wizard forms expose them as
     * `addressCounty`/`addressLocality`. Remap so the auto-filled badge lands on
     * the right inputs (AutoFilledMarker matches by field name).
     *
     * @param  list<string> $autoFilled
     * @return list<string>
     */
    private function remapAddressFields(array $autoFilled): array
    {
        return array_map(static fn (string $f): string => match ($f) {
            'county' => 'addressCounty',
            'locality' => 'addressLocality',
            default => $f,
        }, $autoFilled);
    }

    /**
     * The choice of the one debtor this case is against, one option per party
     * the documents name, labelled by name and CUI. No suggestion: picking the
     * wrong party would put the case against someone the lawyer did not mean.
     *
     * @param list<list<FieldSource>> $clusters
     */
    private function debtorChoice(array $clusters): PrefillConflict
    {
        $options = [];
        foreach ($clusters as $cluster) {
            $name = null;
            $cui = null;
            foreach ($cluster as $source) {
                $name ??= is_string($source->values['name'] ?? null) ? $source->values['name'] : null;
                $cui ??= is_scalar($source->values['cui'] ?? null) ? (string) $source->values['cui'] : null;
            }
            $label = trim(($name ?? '') . ($cui !== null ? ' (CUI ' . $cui . ')' : ''));
            $options[] = new ConflictOption(
                value: $label,
                documentId: $cluster[0]->documentId,
                documentType: $cluster[0]->documentType,
            );
        }

        return new PrefillConflict(
            scope: ConflictScope::DEBTOR_SET,
            severity: ConflictSeverity::ERROR,
            messageKey: self::DEBTOR_CHOICE_MESSAGE,
            field: 'debtors',
            options: $options,
            manualAllowed: false,
        );
    }

    private function buildDebtorEntry(AggregatedFields $aggregated): Step2DebtorEntry
    {
        $v = $aggregated->values;

        return new Step2DebtorEntry(
            personType: $this->toPersonType($v['personType'] ?? null),
            name: $this->toStringOrNull($v['name'] ?? null),
            cui: $this->toStringOrNull($v['cui'] ?? null),
            personalId: $this->toStringOrNull($v['personalId'] ?? null),
            onrcNumber: $this->toStringOrNull($v['onrcNumber'] ?? null),
            address: $this->toStringOrNull($v['address'] ?? null),
            addressCounty: $this->toStringOrNull($v['county'] ?? null),
            addressLocality: $this->toStringOrNull($v['locality'] ?? null),
            email: $this->toStringOrNull($v['email'] ?? null),
            phone: $this->toStringOrNull($v['phone'] ?? null),
            iban: $this->toStringOrNull($v['iban'] ?? null),
            administrator: $this->toStringOrNull($v['administrator'] ?? null),
            autoFilled: $this->remapAddressFields($aggregated->autoFilled),
        );
    }

    private function buildClaimData(AggregatedFields $aggregated): Step3ClaimData
    {
        $v = $aggregated->values;
        $autoFilled = $aggregated->autoFilled;

        // Date fields: parse tolerantly; drop the auto-filled badge when the
        // payload carried a malformed date (the lawyer picks manually). Don't
        // fail the whole prefill over a single bad date.
        $dueDate = $this->toDateOrNull($v['dueDate'] ?? null);
        $invoiceDate = $this->toDateOrNull($v['invoiceDate'] ?? null);
        $contractDate = $this->toDateOrNull($v['contractDate'] ?? null);
        foreach (['dueDate' => $dueDate, 'invoiceDate' => $invoiceDate, 'contractDate' => $contractDate] as $field => $parsed) {
            if ($parsed === null) {
                $autoFilled = array_values(array_filter($autoFilled, static fn (string $f) => $f !== $field));
            }
        }

        // Honour a CONTRACTUAL prefill only with a positive rate: Step3ClaimData
        // requires it via Assert\When/Assert\Positive, so a missing or <= 0 rate
        // would surface a validation error on a prefilled field. Falls back to
        // the DTO default (the AI prompt never emits LEGAL_PENALIZATOARE).
        $penaltyType = $this->toPenaltyType($v['penaltyType'] ?? null);
        $penaltyRate = isset($v['contractualPenaltyRate']) && is_numeric($v['contractualPenaltyRate'])
            ? (float) $v['contractualPenaltyRate']
            : null;
        $penaltyCap = isset($v['contractualPenaltyCapPercent']) && is_numeric($v['contractualPenaltyCapPercent'])
            ? (float) $v['contractualPenaltyCapPercent']
            : null;
        if ($penaltyType !== PenaltyType::CONTRACTUAL || $penaltyRate === null || $penaltyRate <= 0.0) {
            $penaltyType = null;
            $penaltyRate = null;
            $penaltyCap = null;
            $autoFilled = array_values(array_filter(
                $autoFilled,
                static fn (string $f) => !in_array($f, ['penaltyType', 'contractualPenaltyRate', 'contractualPenaltyCapPercent'], true),
            ));
        }

        return new Step3ClaimData(
            amount: isset($v['amount']) && is_numeric($v['amount']) ? (float) $v['amount'] : null,
            currency: is_string($v['currency'] ?? null) && $v['currency'] !== '' ? $v['currency'] : 'RON',
            dueDate: $dueDate,
            legalGround: $this->toLegalGround($v['legalGround'] ?? null),
            description: $this->toStringOrNull($v['description'] ?? null),
            penaltyType: $penaltyType ?? PenaltyType::LEGAL_PENALIZATOARE,
            contractualPenaltyRate: $penaltyRate,
            contractualPenaltyCapPercent: $penaltyCap,
            contractReference: $this->toStringOrNull($v['contractReference'] ?? null),
            invoiceNumber: $this->toStringOrNull($v['invoiceNumber'] ?? null),
            invoiceDate: $invoiceDate,
            contractNumber: $this->toStringOrNull($v['contractNumber'] ?? null),
            contractDate: $contractDate,
            autoFilled: $autoFilled,
        );
    }

    /**
     * @param list<int> $documentIds
     * @return list<Document>
     */
    private function loadDocuments(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        // Ordered by id. The aggregation breaks ties on the document id, so an
        // unordered load would let the database decide which of two equally
        // ranked documents wins, and two runs over the same case could produce
        // two different filings.
        /** @var list<Document> $found */
        $found = $this->documents->findBy(['id' => $documentIds], ['id' => 'ASC']);

        return $found;
    }

    private function toPersonType(mixed $raw): ?PersonType
    {
        if (!is_string($raw)) {
            return null;
        }

        return PersonType::tryFrom($raw);
    }

    private function toLegalGround(mixed $raw): ?LegalGroundCategory
    {
        if (!is_string($raw)) {
            return null;
        }

        return LegalGroundCategory::tryFrom($raw);
    }

    private function toPenaltyType(mixed $raw): ?PenaltyType
    {
        if (!is_string($raw)) {
            return null;
        }

        return PenaltyType::tryFrom($raw);
    }

    private function toDateOrNull(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function toStringOrNull(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
