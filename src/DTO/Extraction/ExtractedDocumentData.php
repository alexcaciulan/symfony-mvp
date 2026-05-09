<?php

namespace App\DTO\Extraction;

final readonly class ExtractedDocumentData
{
    public function __construct(
        public int $sourceDocumentId,
        public string $strategy,
        public float $globalConfidence,
        public \DateTimeImmutable $extractedAt,
        public ?CreditorExtraction $creditor = null,
        public ?DebtorExtraction $debtor = null,
        public ?ClaimExtraction $claim = null,
        public ?string $rawOcrText = null,
    ) {}

    /**
     * Returns a JSON-friendly array shape, suitable for persistence in
     * `Document.extractedData` (Doctrine JSON column).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceDocumentId' => $this->sourceDocumentId,
            'strategy' => $this->strategy,
            'globalConfidence' => $this->globalConfidence,
            'extractedAt' => $this->extractedAt->format(\DateTimeInterface::ATOM),
            'creditor' => $this->creditor !== null ? [
                'personType' => $this->creditor->personType?->value,
                'name' => $this->creditor->name,
                'cui' => $this->creditor->cui,
                'personalId' => $this->creditor->personalId,
                'address' => $this->creditor->address,
                'iban' => $this->creditor->iban,
                'legalRepresentative' => $this->creditor->legalRepresentative,
                'confidencePerField' => $this->creditor->confidencePerField,
            ] : null,
            'debtor' => $this->debtor !== null ? [
                'personType' => $this->debtor->personType?->value,
                'name' => $this->debtor->name,
                'cui' => $this->debtor->cui,
                'personalId' => $this->debtor->personalId,
                'address' => $this->debtor->address,
                'confidencePerField' => $this->debtor->confidencePerField,
            ] : null,
            'claim' => $this->claim !== null ? [
                'amount' => $this->claim->amount,
                'currency' => $this->claim->currency,
                'dueDate' => $this->claim->dueDate?->format(\DateTimeInterface::ATOM),
                'legalGround' => $this->claim->legalGround?->value,
                'description' => $this->claim->description,
                'confidencePerField' => $this->claim->confidencePerField,
            ] : null,
            'rawOcrText' => $this->rawOcrText,
        ];
    }
}
