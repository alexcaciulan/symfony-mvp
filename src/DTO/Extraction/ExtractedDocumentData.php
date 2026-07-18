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
            'debtor' => $this->debtor !== null ? [
                'personType' => $this->debtor->personType?->value,
                'name' => $this->debtor->name,
                'cui' => $this->debtor->cui,
                'isVatPayer' => $this->debtor->isVatPayer,
                'personalId' => $this->debtor->personalId,
                'onrcNumber' => $this->debtor->onrcNumber,
                'address' => $this->debtor->address,
                // county/locality drive competent-court resolution and are read
                // back by PrefillFromExtractionService; they must round-trip here.
                'county' => $this->debtor->county,
                'locality' => $this->debtor->locality,
                'email' => $this->debtor->email,
                'phone' => $this->debtor->phone,
                'iban' => $this->debtor->iban,
                'administrator' => $this->debtor->administrator,
                'confidencePerField' => $this->debtor->confidencePerField,
            ] : null,
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
        ];
    }
}
