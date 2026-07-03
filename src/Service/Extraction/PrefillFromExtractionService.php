<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\Document;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Repository\DocumentRepository;

/**
 * Aggregates extracted data across N documents in a wizard session and produces
 * pre-populated Step DTOs for the wizard forms. For each field, the value with
 * the highest per-field confidence ≥ MIN_CONFIDENCE wins; fields below the
 * threshold are left empty so the lawyer fills them manually.
 *
 * Pas 3.0 builds this so the step-0 side-card ("Date detectate") can render
 * the cross-document preview before the user moves to step 1. Pas 3.1 reuses
 * the same service on entry to steps 1/2/3 to seed each form, and adds
 * Symfony Validator constraints onto the returned DTOs.
 *
 * **Structural contract** (refined in Pas 3.1):
 *   - `aggregateForCreditor()` / `aggregateForDebtor()` / `aggregateForClaim()`
 *     always return a populated DTO instance, possibly with all nullable
 *     fields left null when no document carries above-threshold confidence.
 *   - `aggregateForDebtors()` always returns a `Step2DebtorsData` with
 *     **exactly one** debtor entry, even when `documentIds === []` — so the
 *     form can render the primary debtor card without a "no debtor" branch.
 *     Pas 3.3 lets the user add secondary entries via Live Component.
 *   - `Step3ClaimData::$dueDate` is only marked `autoFilled` if the raw value
 *     parsed successfully into `\DateTimeImmutable`; malformed dates from the
 *     extraction payload are silently dropped (the lawyer picks manually).
 *
 * Resilient to malformed extractedData JSON: tolerates missing keys, null
 * sub-DTOs, and confidence maps with missing fields. A document that's already
 * been deleted (id present in session but row gone) is skipped silently.
 */
final class PrefillFromExtractionService
{
    /**
     * Below this threshold a field is considered "not confident enough to
     * prefill" and is left empty in the resulting DTO. Aligned with the
     * Document badge UX (`auto · N%`) — anything we'd label "auto" must be
     * trustworthy enough for the lawyer to just glance and confirm.
     */
    public const MIN_CONFIDENCE = 0.8;

    public function __construct(
        private readonly DocumentRepository $documents,
    ) {}

    /**
     * @param list<int> $documentIds
     */
    public function aggregateForCreditor(array $documentIds): Step1CreditorData
    {
        $candidates = $this->collectCreditorCandidates($documentIds);

        return $this->buildCreditorData($candidates);
    }

    /**
     * @param list<int> $documentIds
     */
    public function aggregateForDebtor(array $documentIds): Step2DebtorEntry
    {
        $candidates = $this->collectDebtorCandidates($documentIds);

        return $this->buildDebtorEntry($candidates);
    }

    /**
     * @param list<int> $documentIds
     *
     * Pas 3.0 returns a single-entry collection (only the primary debtor is
     * inferable from extraction). Pas 3.3 will let the user add secondary
     * debtors via Step2DebtorsLiveComponent.
     */
    public function aggregateForDebtors(array $documentIds): Step2DebtorsData
    {
        return new Step2DebtorsData([$this->aggregateForDebtor($documentIds)]);
    }

    /**
     * @param list<int> $documentIds
     */
    public function aggregateForClaim(array $documentIds): Step3ClaimData
    {
        $candidates = $this->collectClaimCandidates($documentIds);

        return $this->buildClaimData($candidates);
    }

    /**
     * Reads all `extractedData.creditor` payloads from the given documents and
     * returns a per-field bag of (value, confidence) tuples. Documents without
     * a populated creditor sub-DTO are skipped silently.
     *
     * @param list<int>                                                                $documentIds
     * @return array<string, list<array{value: mixed, confidence: float}>>
     */
    private function collectCreditorCandidates(array $documentIds): array
    {
        $bag = [];
        foreach ($this->loadDocuments($documentIds) as $document) {
            $extracted = $document->getExtractedData();
            $creditor = $extracted['creditor'] ?? null;
            if (!is_array($creditor)) {
                continue;
            }
            $confidence = is_array($creditor['confidencePerField'] ?? null) ? $creditor['confidencePerField'] : [];
            foreach (['personType', 'name', 'cui', 'personalId', 'onrcNumber', 'address', 'email', 'phone', 'iban', 'legalRepresentative', 'bankName'] as $field) {
                $this->captureCandidate($bag, $field, $creditor[$field] ?? null, $confidence[$field] ?? null);
            }
        }

        return $bag;
    }

    /**
     * @param list<int>                                                                $documentIds
     * @return array<string, list<array{value: mixed, confidence: float}>>
     */
    private function collectDebtorCandidates(array $documentIds): array
    {
        $bag = [];
        foreach ($this->loadDocuments($documentIds) as $document) {
            $extracted = $document->getExtractedData();
            $debtor = $extracted['debtor'] ?? null;
            if (!is_array($debtor)) {
                continue;
            }
            $confidence = is_array($debtor['confidencePerField'] ?? null) ? $debtor['confidencePerField'] : [];
            foreach (['personType', 'name', 'cui', 'personalId', 'onrcNumber', 'address', 'county', 'locality', 'email', 'phone', 'iban', 'administrator'] as $field) {
                $this->captureCandidate($bag, $field, $debtor[$field] ?? null, $confidence[$field] ?? null);
            }
        }

        return $bag;
    }

    /**
     * @param list<int>                                                                $documentIds
     * @return array<string, list<array{value: mixed, confidence: float}>>
     */
    private function collectClaimCandidates(array $documentIds): array
    {
        $bag = [];
        foreach ($this->loadDocuments($documentIds) as $document) {
            $extracted = $document->getExtractedData();
            $claim = $extracted['claim'] ?? null;
            if (!is_array($claim)) {
                continue;
            }
            $confidence = is_array($claim['confidencePerField'] ?? null) ? $claim['confidencePerField'] : [];
            foreach (['amount', 'currency', 'dueDate', 'legalGround', 'description', 'invoiceNumber', 'invoiceDate', 'contractNumber', 'contractDate', 'contractReference', 'penaltyType', 'contractualPenaltyRate'] as $field) {
                $this->captureCandidate($bag, $field, $claim[$field] ?? null, $confidence[$field] ?? null);
            }
        }

        return $bag;
    }

    /**
     * @param array<string, list<array{value: mixed, confidence: float}>> $bag
     */
    private function captureCandidate(array &$bag, string $field, mixed $value, mixed $confidence): void
    {
        if ($value === null) {
            return;
        }
        if (!is_numeric($confidence)) {
            return;
        }
        $bag[$field] ??= [];
        $bag[$field][] = ['value' => $value, 'confidence' => (float) $confidence];
    }

    /**
     * @param array<string, list<array{value: mixed, confidence: float}>> $candidates
     * @return array{values: array<string, mixed>, autoFilled: list<string>}
     */
    private function pickBest(array $candidates): array
    {
        $values = [];
        $autoFilled = [];
        foreach ($candidates as $field => $items) {
            $best = null;
            foreach ($items as $item) {
                if ($item['confidence'] < self::MIN_CONFIDENCE) {
                    continue;
                }
                if ($best === null || $item['confidence'] > $best['confidence']) {
                    $best = $item;
                }
            }
            if ($best !== null) {
                $values[$field] = $best['value'];
                $autoFilled[] = $field;
            }
        }

        return ['values' => $values, 'autoFilled' => $autoFilled];
    }

    /**
     * @param array<string, list<array{value: mixed, confidence: float}>> $candidates
     */
    private function buildCreditorData(array $candidates): Step1CreditorData
    {
        $picked = $this->pickBest($candidates);
        $v = $picked['values'];

        return new Step1CreditorData(
            personType: $this->toPersonType($v['personType'] ?? null),
            name: $this->toStringOrNull($v['name'] ?? null),
            cui: $this->toStringOrNull($v['cui'] ?? null),
            personalId: $this->toStringOrNull($v['personalId'] ?? null),
            onrcNumber: $this->toStringOrNull($v['onrcNumber'] ?? null),
            address: $this->toStringOrNull($v['address'] ?? null),
            email: $this->toStringOrNull($v['email'] ?? null),
            phone: $this->toStringOrNull($v['phone'] ?? null),
            iban: $this->toStringOrNull($v['iban'] ?? null),
            legalRepresentative: $this->toStringOrNull($v['legalRepresentative'] ?? null),
            bankName: $this->toStringOrNull($v['bankName'] ?? null),
            autoFilled: $picked['autoFilled'],
        );
    }

    /**
     * @param array<string, list<array{value: mixed, confidence: float}>> $candidates
     */
    private function buildDebtorEntry(array $candidates): Step2DebtorEntry
    {
        $picked = $this->pickBest($candidates);
        $v = $picked['values'];

        // Extraction emits `county`/`locality`; the form fields are
        // `addressCounty`/`addressLocality`. Remap so the ⚡ auto-filled badge
        // lands on the right inputs (AutoFilledMarker matches by field name).
        $autoFilled = array_map(static fn (string $f): string => match ($f) {
            'county' => 'addressCounty',
            'locality' => 'addressLocality',
            default => $f,
        }, $picked['autoFilled']);

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
            autoFilled: $autoFilled,
        );
    }

    /**
     * @param array<string, list<array{value: mixed, confidence: float}>> $candidates
     */
    private function buildClaimData(array $candidates): Step3ClaimData
    {
        $picked = $this->pickBest($candidates);
        $v = $picked['values'];
        $autoFilled = $picked['autoFilled'];

        // Date fields: parse tolerantly; drop the auto-filled badge when the
        // extraction payload carried a malformed date (lawyer picks manually).
        // Don't fail the whole prefill over a single bad date.
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
        if ($penaltyType !== PenaltyType::CONTRACTUAL || $penaltyRate === null || $penaltyRate <= 0.0) {
            $penaltyType = null;
            $penaltyRate = null;
            $autoFilled = array_values(array_filter(
                $autoFilled,
                static fn (string $f) => $f !== 'penaltyType' && $f !== 'contractualPenaltyRate',
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
     * @return iterable<Document>
     */
    private function loadDocuments(array $documentIds): iterable
    {
        if ($documentIds === []) {
            return [];
        }
        // findBy preserves no order — caller doesn't depend on it because we
        // pick across the whole set by confidence anyway.
        return $this->documents->findBy(['id' => $documentIds]);
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
