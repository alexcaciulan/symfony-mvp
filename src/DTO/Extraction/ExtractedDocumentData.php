<?php

namespace App\DTO\Extraction;

use App\Enum\ExtractionFailureReason;

final readonly class ExtractedDocumentData
{
    /**
     * Payload shape written by the classification-aware extractor. Payloads
     * without the marker were written before it existed and carry neither a
     * classification nor per-type sections; readers must keep handling them
     * because no data migration rewrites them (provenance is worth more than
     * a uniform shape).
     */
    public const SCHEMA_VERSION = 2;

    /**
     * @param ?ExtractionFailureReason $failureReason set only on empty results,
     *        so the orchestrator can persist a cause on the Document instead of
     *        leaving every failure mode looking alike
     * @param ?DocumentClassification $classification what the model believes the
     *        document is; null when no classifying prompt ran (legacy strategies,
     *        specialised prompts on an already-known type, empty results)
     * @param list<DebtorExtraction> $debtors every debtor the document names.
     *        A list rather than one, because a single field was the root cause
     *        of the wizard collapsing several debtors into one chimerical party:
     *        with nowhere to put the second, everything downstream had to merge.
     */
    public function __construct(
        public int $sourceDocumentId,
        public string $strategy,
        public float $globalConfidence,
        public \DateTimeImmutable $extractedAt,
        public ?CreditorExtraction $creditor = null,
        public array $debtors = [],
        public ?ClaimExtraction $claim = null,
        public ?string $rawOcrText = null,
        public ?ExtractionFailureReason $failureReason = null,
        public ?DocumentClassification $classification = null,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /**
     * Version of a persisted payload. Absent marker means version 1, the shape
     * written before classification existed.
     *
     * @param array<string, mixed> $payload
     */
    public static function schemaVersionOf(array $payload): int
    {
        $raw = $payload['schemaVersion'] ?? null;

        return is_int($raw) && $raw > 0 ? $raw : 1;
    }

    /**
     * The debtor sections of a persisted payload, whatever shape it was written
     * in.
     *
     * Payloads written before a document could name more than one debtor carry
     * a single `debtor` object. They are not migrated and must not be: the
     * payload is evidence of what was extracted at the time, and rewriting it
     * would make a case file disagree with the audit trail that describes it.
     * So the reader normalises instead, and a single object reads back as a
     * list of one.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function debtorPayloadsOf(array $payload): array
    {
        $list = $payload['debtors'] ?? null;
        if (is_array($list) && $list !== [] && array_is_list($list)) {
            return array_values(array_filter($list, static fn (mixed $entry): bool => is_array($entry)));
        }

        $single = $payload['debtor'] ?? null;

        return is_array($single) ? [$single] : [];
    }

    /**
     * The debtor a single-debtor consumer should use, for the surfaces that
     * still show exactly one.
     */
    public function primaryDebtor(): ?DebtorExtraction
    {
        return $this->debtors[0] ?? null;
    }

    /**
     * Returns a JSON-friendly array shape, suitable for persistence in
     * `Document.extractedData` (Doctrine JSON column).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'classification' => $this->classification?->toArray(),
            'sourceDocumentId' => $this->sourceDocumentId,
            'strategy' => $this->strategy,
            'globalConfidence' => $this->globalConfidence,
            'extractedAt' => $this->extractedAt->format(\DateTimeInterface::ATOM),
            'creditor' => $this->creditor !== null ? [
                'personType' => $this->creditor->personType?->value,
                'name' => $this->creditor->name,
                'cui' => $this->creditor->cui,
                'isVatPayer' => $this->creditor->isVatPayer,
                'personalId' => $this->creditor->personalId,
                'onrcNumber' => $this->creditor->onrcNumber,
                'address' => $this->creditor->address,
                // county/locality select the stamp-duty town hall and are read
                // back by PrefillFromExtractionService; they must round-trip here.
                'county' => $this->creditor->county,
                'locality' => $this->creditor->locality,
                'email' => $this->creditor->email,
                'phone' => $this->creditor->phone,
                'iban' => $this->creditor->iban,
                'legalRepresentative' => $this->creditor->legalRepresentative,
                'bankName' => $this->creditor->bankName,
                'confidencePerField' => $this->creditor->confidencePerField,
            ] : null,
            'debtors' => array_map(self::debtorToArray(...), array_values($this->debtors)),
            // The first debtor is written under the old key as well. Readers
            // not yet moved to the list keep seeing the shape they were written
            // against; the list above stays the source of truth.
            'debtor' => $this->primaryDebtor() !== null
                ? self::debtorToArray($this->primaryDebtor())
                : null,
            'claim' => $this->claim !== null ? [
                'amount' => $this->claim->amount,
                'currency' => $this->claim->currency,
                'dueDate' => $this->claim->dueDate?->format(\DateTimeInterface::ATOM),
                'legalGround' => $this->claim->legalGround?->value,
                'description' => $this->claim->description,
                'invoiceNumber' => $this->claim->invoiceNumber,
                'invoiceDate' => $this->claim->invoiceDate?->format(\DateTimeInterface::ATOM),
                'contractNumber' => $this->claim->contractNumber,
                'contractDate' => $this->claim->contractDate?->format(\DateTimeInterface::ATOM),
                'contractReference' => $this->claim->contractReference,
                'penaltyType' => $this->claim->penaltyType?->value,
                'contractualPenaltyRate' => $this->claim->contractualPenaltyRate,
                'confidencePerField' => $this->claim->confidencePerField,
            ] : null,
            'rawOcrText' => $this->rawOcrText,
            'failureReason' => $this->failureReason?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function debtorToArray(DebtorExtraction $debtor): array
    {
        return [
            'personType' => $debtor->personType?->value,
            'name' => $debtor->name,
            'cui' => $debtor->cui,
            'isVatPayer' => $debtor->isVatPayer,
            'personalId' => $debtor->personalId,
            'onrcNumber' => $debtor->onrcNumber,
            'address' => $debtor->address,
            // county/locality drive competent-court resolution and are read
            // back by PrefillFromExtractionService; they must round-trip here.
            'county' => $debtor->county,
            'locality' => $debtor->locality,
            'email' => $debtor->email,
            'phone' => $debtor->phone,
            'iban' => $debtor->iban,
            'administrator' => $debtor->administrator,
            'confidencePerField' => $debtor->confidencePerField,
        ];
    }
}
