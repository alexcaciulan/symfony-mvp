<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\DocumentClassification;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * Which document's figures win when several documents in one session state a
 * sum. They all write into the same `claim.amount`, but they do not mean the
 * same thing: a contract states a price, an invoice states what is owed, a
 * bank statement a balance. Picking on self-reported confidence alone lets the
 * wrong one become the principal of the filing, and the principal decides the
 * stamp duty and the competent court.
 */
final class ClaimAuthorityPrefillTest extends TestCase
{
    public function testAnInvoiceOutranksAContractOnTheAmountEvenWhenLessConfident(): void
    {
        // The concrete case: a firm contract price the model is very sure of,
        // next to the invoice that was actually left unpaid.
        $claim = $this->aggregate([
            $this->document(DocumentType::CONTRACT, amount: 100000.0, confidence: 0.99),
            $this->document(DocumentType::FACTURA, amount: 12000.0, confidence: 0.90),
        ]);

        self::assertSame(12000.0, $claim->amount);
    }

    public function testABankStatementBalanceNeverOutranksAnInvoice(): void
    {
        $claim = $this->aggregate([
            $this->document(DocumentType::EXTRAS_CONT, amount: 3400.0, confidence: 1.0),
            $this->document(DocumentType::FACTURA, amount: 12000.0, confidence: 0.85),
        ]);

        self::assertSame(12000.0, $claim->amount);
    }

    public function testAContractIsStillUsedWhenItIsTheOnlySource(): void
    {
        // A loan contract is the claim, so ranking must not mean discarding.
        $claim = $this->aggregate([
            $this->document(DocumentType::CONTRACT, amount: 50000.0, confidence: 0.95),
        ]);

        self::assertSame(50000.0, $claim->amount);
    }

    public function testConfidenceStillDecidesBetweenTwoDocumentsOfTheSameKind(): void
    {
        $claim = $this->aggregate([
            $this->document(DocumentType::FACTURA, amount: 900.0, confidence: 0.85),
            $this->document(DocumentType::FACTURA, amount: 1500.0, confidence: 0.95),
        ]);

        self::assertSame(1500.0, $claim->amount);
    }

    public function testAnUndeclaredDocumentIsNotStarved(): void
    {
        // Most uploads carry no declared type until a detection is adopted. If
        // the neutral rank lost to everything, the common case would prefill
        // nothing at all.
        $claim = $this->aggregate([
            $this->document(DocumentType::ALT_DOCUMENT, amount: 7300.0, confidence: 0.92),
        ]);

        self::assertSame(7300.0, $claim->amount);
    }

    public function testAConfidentDetectionRanksAsIfItHadBeenDeclared(): void
    {
        // The type the wizard shows after a promotion is the detected one, so
        // ranking has to read it too, under the same threshold that allowed the
        // promotion in the first place.
        $detectedInvoice = $this->document(DocumentType::ALT_DOCUMENT, amount: 12000.0, confidence: 0.85);
        $detectedInvoice->setDetectedType(DocumentType::FACTURA);
        $detectedInvoice->setDetectedTypeConfidence('0.95');

        $claim = $this->aggregate([
            $this->document(DocumentType::EXTRAS_CONT, amount: 3400.0, confidence: 0.99),
            $detectedInvoice,
        ]);

        self::assertSame(12000.0, $claim->amount);
    }

    public function testAnUnsureDetectionDoesNotChangeTheRanking(): void
    {
        // Below the adoption threshold the detection is a guess a human has yet
        // to confirm, so it must not reorder the sums either.
        $guessed = $this->document(DocumentType::ALT_DOCUMENT, amount: 3400.0, confidence: 0.99);
        $guessed->setDetectedType(DocumentType::FACTURA);
        $guessed->setDetectedTypeConfidence((string) DocumentClassification::ADOPTION_THRESHOLD);

        $claim = $this->aggregate([
            $guessed,
            $this->document(DocumentType::FACTURA, amount: 12000.0, confidence: 0.85),
        ]);

        self::assertSame(12000.0, $claim->amount);
    }

    public function testTheInvoiceNumberComesFromWhereTheSumCameFrom(): void
    {
        // The number and the sum are read together, from the same document.
        // Taking the more confident number from the demand letter while the sum
        // comes from the invoice would put a petition in front of a court that
        // names one invoice and claims the total of another. A demand letter
        // that quotes a number more legibly is not authority over which invoice
        // the claim rests on.
        $invoice = $this->document(DocumentType::FACTURA, amount: 12000.0, confidence: 0.85);
        $invoice->setExtractedData([
            'claim' => [
                'amount' => 12000.0,
                'invoiceNumber' => 'MJ-1',
                'confidencePerField' => ['amount' => 0.85, 'invoiceNumber' => 0.85],
            ],
        ]);
        $notice = $this->document(DocumentType::SOMATIE_ANTERIOARA, amount: 12000.0, confidence: 0.85);
        $notice->setExtractedData([
            'claim' => [
                'invoiceNumber' => 'MJ 2024-00123',
                'confidencePerField' => ['invoiceNumber' => 0.99],
            ],
        ]);

        $claim = $this->aggregate([$invoice, $notice]);

        self::assertSame('MJ-1', $claim->invoiceNumber);
        self::assertSame(12000.0, $claim->amount);
    }

    /**
     * @param list<Document> $documents
     */
    private function aggregate(array $documents): \App\DTO\Wizard\Step3ClaimData
    {
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findBy')->willReturn($documents);

        return (new PrefillFromExtractionService($repo))->aggregateForClaim([1, 2]);
    }

    private function document(DocumentType $type, float $amount, float $confidence): Document
    {
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData([
            'claim' => [
                'amount' => $amount,
                'currency' => 'RON',
                'confidencePerField' => ['amount' => $confidence, 'currency' => $confidence],
            ],
        ]);

        return $document;
    }
}
