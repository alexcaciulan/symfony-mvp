<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\Document;
use App\Enum\LegalGroundCategory;
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
 * the same service on entry to steps 1/2/3 to seed each form.
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
            foreach (['personType', 'name', 'cui', 'personalId', 'address', 'iban', 'legalRepresentative'] as $field) {
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
            foreach (['personType', 'name', 'cui', 'personalId', 'address'] as $field) {
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
            foreach (['amount', 'currency', 'dueDate', 'legalGround', 'description'] as $field) {
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
            address: $this->toStringOrNull($v['address'] ?? null),
            iban: $this->toStringOrNull($v['iban'] ?? null),
            legalRepresentative: $this->toStringOrNull($v['legalRepresentative'] ?? null),
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

        return new Step2DebtorEntry(
            personType: $this->toPersonType($v['personType'] ?? null),
            name: $this->toStringOrNull($v['name'] ?? null),
            cui: $this->toStringOrNull($v['cui'] ?? null),
            personalId: $this->toStringOrNull($v['personalId'] ?? null),
            address: $this->toStringOrNull($v['address'] ?? null),
            autoFilled: $picked['autoFilled'],
        );
    }

    /**
     * @param array<string, list<array{value: mixed, confidence: float}>> $candidates
     */
    private function buildClaimData(array $candidates): Step3ClaimData
    {
        $picked = $this->pickBest($candidates);
        $v = $picked['values'];

        $dueDate = null;
        if (isset($v['dueDate']) && is_string($v['dueDate']) && $v['dueDate'] !== '') {
            try {
                $dueDate = new \DateTimeImmutable($v['dueDate']);
            } catch (\Exception) {
                // Malformed date in extraction payload — drop the field, the
                // lawyer will pick it manually. Don't fail the whole prefill.
                $dueDate = null;
            }
        }

        $autoFilled = $picked['autoFilled'];
        if ($dueDate === null) {
            $autoFilled = array_values(array_filter($autoFilled, static fn (string $f) => $f !== 'dueDate'));
        }

        return new Step3ClaimData(
            amount: isset($v['amount']) && is_numeric($v['amount']) ? (float) $v['amount'] : null,
            currency: is_string($v['currency'] ?? null) && $v['currency'] !== '' ? $v['currency'] : 'RON',
            dueDate: $dueDate,
            legalGround: $this->toLegalGround($v['legalGround'] ?? null),
            description: $this->toStringOrNull($v['description'] ?? null),
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

    private function toStringOrNull(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
